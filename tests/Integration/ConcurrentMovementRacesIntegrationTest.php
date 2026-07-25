<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Db\MovementMapper;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\MovementService;
use Test\TestCase;

/**
 * AC-8 concurrent adjust-set + AC-9 opposite transfers (dual-process).
 *
 * @group DB
 */
final class ConcurrentMovementRacesIntegrationTest extends TestCase
{
	use DualProcessRaceTrait;

	public function testOppositeTransfersCompleteWithoutDeadlock(): void
	{
		$app = new Application();
		$c = $app->getContainer();
		$movements = $c->get(MovementService::class);
		$locations = $c->get(LocationService::class);
		$items = $c->get(ItemService::class);
		$balances = $c->get(BalanceMapper::class);

		$suffix = bin2hex(random_bytes(3));
		$uid = 'admin';
		$a = $locations->create($uid, ['code' => 'OT-A-' . $suffix, 'name' => 'A', 'kind' => 'warehouse']);
		$b = $locations->create($uid, ['code' => 'OT-B-' . $suffix, 'name' => 'B', 'kind' => 'van']);
		$item = $items->create($uid, ['sku' => 'OT-I-' . $suffix, 'name' => 'Item']);
		$itemId = (int)$item['id'];
		$aId = (int)$a['id'];
		$bId = (int)$b['id'];
		$movements->receive($uid, $itemId, $aId, 10, null);
		$movements->receive($uid, $itemId, $bId, 10, null);

		[$outA, $outB] = $this->runDualWorkers(static function (string $resultFile, string $goFile, string $root) use ($itemId, $aId, $bId): string {
			// Worker role encoded in result path suffix.
			$isA = str_ends_with($resultFile, 'a.txt');
			$from = $isA ? $aId : $bId;
			$to = $isA ? $bId : $aId;
			return <<<PHP
<?php
require '$root/lib/base.php';
\$go = '$goFile';
\$deadline = microtime(true) + 10.0;
while (!is_file(\$go) && microtime(true) < \$deadline) { usleep(5000); }
if (!is_file(\$go)) { file_put_contents('$resultFile', "timeout\\n"); exit(3); }
try {
	\$app = new \\OCA\\InventoryCheck\\AppInfo\\Application();
	\$movements = \$app->getContainer()->get(\\OCA\\InventoryCheck\\Service\\MovementService::class);
	\$movements->transfer('admin', $itemId, $from, $to, 5, 'race');
	file_put_contents('$resultFile', "ok\\n");
	exit(0);
} catch (\\Throwable \$e) {
	file_put_contents('$resultFile', get_class(\$e) . ':' . \$e->getMessage() . "\\n");
	exit(2);
}
PHP;
		});

		$this->assertSame('ok', $outA, "A→B failed: $outA");
		$this->assertSame('ok', $outB, "B→A failed: $outB");
		$this->assertSame(10, $balances->findPair($itemId, $aId)?->getQty());
		$this->assertSame(10, $balances->findPair($itemId, $bId)?->getQty());
		$this->assertLedgerMatches($c->get(MovementMapper::class), $balances, $itemId);
	}

	public function testConcurrentAdjustSetEndsOnOneTargetWithConsistentLedger(): void
	{
		$app = new Application();
		$c = $app->getContainer();
		$movements = $c->get(MovementService::class);
		$locations = $c->get(LocationService::class);
		$items = $c->get(ItemService::class);
		$balances = $c->get(BalanceMapper::class);
		$movementMapper = $c->get(MovementMapper::class);

		$suffix = bin2hex(random_bytes(3));
		$uid = 'admin';
		$loc = $locations->create($uid, ['code' => 'AS-' . $suffix, 'name' => 'Loc', 'kind' => 'other']);
		$item = $items->create($uid, ['sku' => 'ASI-' . $suffix, 'name' => 'Item']);
		$itemId = (int)$item['id'];
		$locId = (int)$loc['id'];
		$movements->receive($uid, $itemId, $locId, 10, null);
		$beforeCount = $movementMapper->search(null, $itemId, $locId, null, null, null, 200, 0)['total'];

		[$outA, $outB] = $this->runDualWorkers(static function (string $resultFile, string $goFile, string $root) use ($itemId, $locId): string {
			$target = str_ends_with($resultFile, 'a.txt') ? 3 : 7;
			return <<<PHP
<?php
require '$root/lib/base.php';
\$go = '$goFile';
\$deadline = microtime(true) + 10.0;
while (!is_file(\$go) && microtime(true) < \$deadline) { usleep(5000); }
if (!is_file(\$go)) { file_put_contents('$resultFile', "timeout\\n"); exit(3); }
try {
	\$app = new \\OCA\\InventoryCheck\\AppInfo\\Application();
	\$movements = \$app->getContainer()->get(\\OCA\\InventoryCheck\\Service\\MovementService::class);
	\$movements->adjust('admin', $itemId, $locId, 'set', $target, null, 'race-set');
	file_put_contents('$resultFile', "ok:$target\\n");
	exit(0);
} catch (\\Throwable \$e) {
	file_put_contents('$resultFile', get_class(\$e) . ':' . \$e->getMessage() . "\\n");
	exit(2);
}
PHP;
		});

		$this->assertStringStartsWith('ok:', $outA, $outA);
		$this->assertStringStartsWith('ok:', $outB, $outB);
		$final = $balances->findPair($itemId, $locId)?->getQty();
		$this->assertContains($final, [3, 7], "final qty must be one of the set targets, got $final");
		$after = $movementMapper->search(null, $itemId, $locId, null, null, null, 200, 0);
		$this->assertSame($beforeCount + 2, $after['total'], 'both adjust rows must land');
		$this->assertLedgerMatches($movementMapper, $balances, $itemId);
	}

	private function assertLedgerMatches(MovementMapper $movements, BalanceMapper $balances, int $itemId): void
	{
		$sums = $movements->sumDeltasByPair();
		$bals = $balances->search($itemId, null, false, 200, 0);
		foreach ($bals['data'] as $bal) {
			$key = $bal->getItemId() . ':' . $bal->getLocationId();
			$this->assertSame($sums[$key] ?? 0, $bal->getQty(), "ledger drift at $key");
		}
	}
}
