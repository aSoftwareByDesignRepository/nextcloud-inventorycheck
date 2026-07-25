<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\MovementService;
use Test\TestCase;

/**
 * True dual-process last-unit race (AC-8 / E8).
 * Two PHP children both issue the last units; exactly one must succeed.
 *
 * @group DB
 */
final class ConcurrentIssueLockIntegrationTest extends TestCase
{
	public function testParallelIssueProcessesExactlyOneWins(): void
	{
		$app = new Application();
		$c = $app->getContainer();
		/** @var MovementService $movements */
		$movements = $c->get(MovementService::class);
		/** @var LocationService $locations */
		$locations = $c->get(LocationService::class);
		/** @var ItemService $items */
		$items = $c->get(ItemService::class);
		/** @var BalanceMapper $balances */
		$balances = $c->get(BalanceMapper::class);
		/** @var AccessControlService $acl */
		$acl = $c->get(AccessControlService::class);

		// Pin policy for the race: sibling tests may flip allow_negative_stock.
		$prevAllowNeg = $acl->allowNegativeStock();
		$acl->setAllowNegativeStock(false);

		try {
			$suffix = bin2hex(random_bytes(3));
			$uid = 'admin';
			$loc = $locations->create($uid, [
				'code' => 'CX-' . $suffix, 'name' => 'Loc', 'kind' => 'other',
			]);
			$item = $items->create($uid, [
				'sku' => 'CI-' . $suffix, 'name' => 'Item',
			]);
			$itemId = (int)$item['id'];
			$locId = (int)$loc['id'];
			$movements->receive($uid, $itemId, $locId, 5, null);

			$root = dirname(__DIR__, 4); // .../html or nextcloud root from custom_apps/inventorycheck/tests/Integration
			// Path: apps/inventorycheck/tests/Integration → up 4 = nextcloud root (apps→html in docker)
			if (!is_file($root . '/lib/base.php')) {
				$root = '/var/www/html';
			}
			$this->assertFileExists($root . '/lib/base.php');

			$dir = sys_get_temp_dir() . '/iv-race-' . bin2hex(random_bytes(4));
			mkdir($dir, 0700);
			$goFile = $dir . '/go';
			$resultA = $dir . '/a.txt';
			$resultB = $dir . '/b.txt';

			$worker = static function (string $resultFile, int $itemId, int $locId, string $goFile, string $root): string {
				return <<<PHP
<?php
require '$root/lib/base.php';
\$go = '$goFile';
\$deadline = microtime(true) + 8.0;
while (!is_file(\$go) && microtime(true) < \$deadline) {
	usleep(5000);
}
if (!is_file(\$go)) {
	file_put_contents('$resultFile', "timeout\\n");
	exit(3);
}
try {
	\$app = new \\OCA\\InventoryCheck\\AppInfo\\Application();
	\$c = \$app->getContainer();
	\$c->get(\\OCA\\InventoryCheck\\Service\\AccessControlService::class)->setAllowNegativeStock(false);
	\$movements = \$c->get(\\OCA\\InventoryCheck\\Service\\MovementService::class);
	\$movements->issue('admin', $itemId, $locId, 5, 'race');
	file_put_contents('$resultFile', "ok\\n");
	exit(0);
} catch (\\OCA\\InventoryCheck\\Exception\\InsufficientStockException \$e) {
	file_put_contents('$resultFile', "insufficient\\n");
	exit(1);
} catch (\\Throwable \$e) {
	file_put_contents('$resultFile', get_class(\$e) . ':' . \$e->getMessage() . "\\n");
	exit(2);
}
PHP;
			};

			$scriptA = $dir . '/worker-a.php';
			$scriptB = $dir . '/worker-b.php';
			file_put_contents($scriptA, $worker($resultA, $itemId, $locId, $goFile, $root));
			file_put_contents($scriptB, $worker($resultB, $itemId, $locId, $goFile, $root));

			$descriptors = [
				0 => ['pipe', 'r'],
				1 => ['pipe', 'w'],
				2 => ['pipe', 'w'],
			];
			$pA = proc_open('php ' . escapeshellarg($scriptA), $descriptors, $pipesA, null, null);
			$pB = proc_open('php ' . escapeshellarg($scriptB), $descriptors, $pipesB, null, null);
			$this->assertIsResource($pA);
			$this->assertIsResource($pB);
			foreach (array_merge($pipesA, $pipesB) as $pipe) {
				fclose($pipe);
			}

			usleep(150000); // let both reach the barrier
			file_put_contents($goFile, '1');

			$statusA = proc_close($pA);
			$statusB = proc_close($pB);
			$outA = is_file($resultA) ? trim((string)file_get_contents($resultA)) : 'missing';
			$outB = is_file($resultB) ? trim((string)file_get_contents($resultB)) : 'missing';

			// Cleanup temp dir
			foreach ([$scriptA, $scriptB, $resultA, $resultB, $goFile] as $f) {
				if (is_file($f)) {
					unlink($f);
				}
			}
			@rmdir($dir);

			$results = [$outA, $outB];
			sort($results);
			$this->assertSame(
				['insufficient', 'ok'],
				$results,
				"expected one ok and one insufficient; got A=$outA ($statusA) B=$outB ($statusB)",
			);
			$this->assertSame(0, $balances->findPair($itemId, $locId)?->getQty());
		} finally {
			$acl->setAllowNegativeStock($prevAllowNeg);
		}
	}
}
