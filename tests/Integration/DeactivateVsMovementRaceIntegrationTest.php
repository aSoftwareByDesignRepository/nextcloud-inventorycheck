<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\LocationMapper;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\MovementService;
use Test\TestCase;

/**
 * S5 TOCTOU: a movement racing a deactivation must never strand stock on an
 * inactive item/location. Exactly one of the two workers wins:
 *   - movement commits first → deactivate sees stock → 409 *_has_stock
 *   - deactivate commits first → movement sees inactive → 422 inactive_*
 *
 * @group DB
 */
final class DeactivateVsMovementRaceIntegrationTest extends TestCase
{
	use DualProcessRaceTrait;

	public function testReceiveVsItemDeactivateNeverStrandsStock(): void
	{
		$app = new Application();
		$c = $app->getContainer();
		$locations = $c->get(LocationService::class);
		$items = $c->get(ItemService::class);
		$balances = $c->get(BalanceMapper::class);
		$itemMapper = $c->get(ItemMapper::class);

		$suffix = bin2hex(random_bytes(3));
		$uid = 'admin';
		$loc = $locations->create($uid, ['code' => 'DR-L-' . $suffix, 'name' => 'Loc', 'kind' => 'other']);
		$item = $items->create($uid, ['sku' => 'DR-I-' . $suffix, 'name' => 'Item']);
		$itemId = (int)$item['id'];
		$locId = (int)$loc['id'];

		[$outA, $outB] = $this->runDualWorkers(static function (string $resultFile, string $goFile, string $root) use ($itemId, $locId): string {
			$isMover = str_ends_with($resultFile, 'a.txt');
			$body = $isMover
				? "\$svc = \$app->getContainer()->get(\\OCA\\InventoryCheck\\Service\\MovementService::class);\n\t\$svc->receive('admin', $itemId, $locId, 5, 'race');"
				: "\$svc = \$app->getContainer()->get(\\OCA\\InventoryCheck\\Service\\ItemService::class);\n\t\$svc->update('admin', $itemId, ['active' => false]);";
			return <<<PHP
<?php
require '$root/lib/base.php';
\$go = '$goFile';
\$deadline = microtime(true) + 10.0;
while (!is_file(\$go) && microtime(true) < \$deadline) { usleep(5000); }
if (!is_file(\$go)) { file_put_contents('$resultFile', "timeout\\n"); exit(3); }
try {
	\$app = new \\OCA\\InventoryCheck\\AppInfo\\Application();
	$body
	file_put_contents('$resultFile', "ok\\n");
	exit(0);
} catch (\\Throwable \$e) {
	\$code = method_exists(\$e, 'getErrorCode') ? \$e->getErrorCode() : get_class(\$e);
	file_put_contents('$resultFile', "err:\$code\\n");
	exit(2);
}
PHP;
		});

		$this->assertContains($outA, ['ok', 'err:inactive_item'], "mover outcome: $outA");
		$this->assertContains($outB, ['ok', 'err:item_has_stock'], "deactivator outcome: $outB");
		$this->assertNotSame(
			['ok', 'ok'],
			[$outA, $outB],
			'movement and deactivation must serialise — both cannot win',
		);

		$active = $itemMapper->findById($itemId)->getActive();
		$qty = $balances->findPair($itemId, $locId)?->getQty() ?? 0;
		$this->assertFalse(!$active && $qty !== 0, "stranded stock: inactive item with qty $qty");
	}

	public function testReceiveVsLocationDeactivateNeverStrandsStock(): void
	{
		$app = new Application();
		$c = $app->getContainer();
		$locations = $c->get(LocationService::class);
		$items = $c->get(ItemService::class);
		$balances = $c->get(BalanceMapper::class);
		$locationMapper = $c->get(LocationMapper::class);

		$suffix = bin2hex(random_bytes(3));
		$uid = 'admin';
		$loc = $locations->create($uid, ['code' => 'DR-LL-' . $suffix, 'name' => 'Loc', 'kind' => 'van']);
		$item = $items->create($uid, ['sku' => 'DR-LI-' . $suffix, 'name' => 'Item']);
		$itemId = (int)$item['id'];
		$locId = (int)$loc['id'];

		[$outA, $outB] = $this->runDualWorkers(static function (string $resultFile, string $goFile, string $root) use ($itemId, $locId): string {
			$isMover = str_ends_with($resultFile, 'a.txt');
			$body = $isMover
				? "\$svc = \$app->getContainer()->get(\\OCA\\InventoryCheck\\Service\\MovementService::class);\n\t\$svc->receive('admin', $itemId, $locId, 5, 'race');"
				: "\$svc = \$app->getContainer()->get(\\OCA\\InventoryCheck\\Service\\LocationService::class);\n\t\$svc->update('admin', $locId, ['active' => false]);";
			return <<<PHP
<?php
require '$root/lib/base.php';
\$go = '$goFile';
\$deadline = microtime(true) + 10.0;
while (!is_file(\$go) && microtime(true) < \$deadline) { usleep(5000); }
if (!is_file(\$go)) { file_put_contents('$resultFile', "timeout\\n"); exit(3); }
try {
	\$app = new \\OCA\\InventoryCheck\\AppInfo\\Application();
	$body
	file_put_contents('$resultFile', "ok\\n");
	exit(0);
} catch (\\Throwable \$e) {
	\$code = method_exists(\$e, 'getErrorCode') ? \$e->getErrorCode() : get_class(\$e);
	file_put_contents('$resultFile', "err:\$code\\n");
	exit(2);
}
PHP;
		});

		$this->assertContains($outA, ['ok', 'err:inactive_location'], "mover outcome: $outA");
		$this->assertContains($outB, ['ok', 'err:location_has_stock'], "deactivator outcome: $outB");
		$this->assertNotSame(
			['ok', 'ok'],
			[$outA, $outB],
			'movement and deactivation must serialise — both cannot win',
		);

		$active = $locationMapper->findById($locId)->getActive();
		$qty = $balances->findPair($itemId, $locId)?->getQty() ?? 0;
		$this->assertFalse(!$active && $qty !== 0, "stranded stock: inactive location with qty $qty");
	}
}
