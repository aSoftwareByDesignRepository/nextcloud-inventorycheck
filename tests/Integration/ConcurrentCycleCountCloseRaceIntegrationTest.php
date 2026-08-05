<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Service\CycleCountService;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\MovementService;
use Test\TestCase;

/**
 * UC-C2 race: concurrent receive during inventur close must not wipe stock.
 *
 * Without balance FOR UPDATE before the conflict decision, close can read
 * system==current, then a receive commits, then adjust(set) reverts it.
 *
 * @group DB
 */
final class ConcurrentCycleCountCloseRaceIntegrationTest extends TestCase
{
	public function testCloseVersusReceiveNeverWipesMidCountStock(): void
	{
		$app = new Application();
		$c = $app->getContainer();
		/** @var MovementService $movements */
		$movements = $c->get(MovementService::class);
		/** @var LocationService $locations */
		$locations = $c->get(LocationService::class);
		/** @var ItemService $items */
		$items = $c->get(ItemService::class);
		/** @var CycleCountService $counts */
		$counts = $c->get(CycleCountService::class);
		/** @var BalanceMapper $balances */
		$balances = $c->get(BalanceMapper::class);

		$suffix = bin2hex(random_bytes(3));
		$uid = 'admin';
		$loc = $locations->create($uid, [
			'code' => 'CCR-L-' . $suffix,
			'name' => 'Race Loc',
			'kind' => 'warehouse',
		]);
		$item = $items->create($uid, [
			'sku' => 'CCR-I-' . $suffix,
			'name' => 'Race Item',
			'uom' => 'pcs',
			'reorderLevel' => 0,
		]);
		$itemId = (int)$item['id'];
		$locId = (int)$loc['id'];
		$movements->receive($uid, $itemId, $locId, 10, 'seed');

		$camp = $counts->create($uid, $locId, 'Race ' . $suffix);
		$counts->startCounting($uid, (int)$camp['id']);
		$lineId = null;
		$offset = 0;
		$limit = 200;
		do {
			$page = $counts->get($uid, (int)$camp['id'], $limit, $offset);
			foreach ($page['lines'] as $line) {
				if ((int)$line['itemId'] === $itemId) {
					$lineId = (int)$line['id'];
					break 2;
				}
			}
			$offset += $limit;
		} while ($offset < (int)($page['linesTotal'] ?? 0));
		self::assertNotNull($lineId, 'inventur line for seeded item must exist');
		$counts->setCount($uid, $lineId, 10);
		$campaignId = (int)$camp['id'];

		$root = dirname(__DIR__, 4);
		if (!is_file($root . '/lib/base.php')) {
			$root = '/var/www/html';
		}
		$this->assertFileExists($root . '/lib/base.php');

		$dir = sys_get_temp_dir() . '/iv-cc-race-' . bin2hex(random_bytes(4));
		mkdir($dir, 0700);
		$goFile = $dir . '/go';
		$resultClose = $dir . '/close.txt';
		$resultRecv = $dir . '/recv.txt';

		$closeWorker = <<<PHP
<?php
require '$root/lib/base.php';
\$go = '$goFile';
\$deadline = microtime(true) + 8.0;
while (!is_file(\$go) && microtime(true) < \$deadline) {
	usleep(5000);
}
if (!is_file(\$go)) {
	file_put_contents('$resultClose', "timeout\\n");
	exit(3);
}
try {
	\$app = new \\OCA\\InventoryCheck\\AppInfo\\Application();
	\$c = \$app->getContainer();
	\$counts = \$c->get(\\OCA\\InventoryCheck\\Service\\CycleCountService::class);
	\$counts->close('admin', $campaignId, true, false);
	file_put_contents('$resultClose', "closed\\n");
	exit(0);
} catch (\\OCA\\InventoryCheck\\Exception\\ValidationException \$e) {
	file_put_contents('$resultClose', \$e->getErrorCode() . "\\n");
	exit(1);
} catch (\\Throwable \$e) {
	file_put_contents('$resultClose', get_class(\$e) . ':' . \$e->getMessage() . "\\n");
	exit(2);
}
PHP;

		$recvWorker = <<<PHP
<?php
require '$root/lib/base.php';
\$go = '$goFile';
\$deadline = microtime(true) + 8.0;
while (!is_file(\$go) && microtime(true) < \$deadline) {
	usleep(5000);
}
if (!is_file(\$go)) {
	file_put_contents('$resultRecv', "timeout\\n");
	exit(3);
}
try {
	\$app = new \\OCA\\InventoryCheck\\AppInfo\\Application();
	\$c = \$app->getContainer();
	\$movements = \$c->get(\\OCA\\InventoryCheck\\Service\\MovementService::class);
	\$movements->receive('admin', $itemId, $locId, 2, 'race-receive');
	file_put_contents('$resultRecv', "received\\n");
	exit(0);
} catch (\\Throwable \$e) {
	file_put_contents('$resultRecv', get_class(\$e) . ':' . \$e->getMessage() . "\\n");
	exit(2);
}
PHP;

		$scriptClose = $dir . '/worker-close.php';
		$scriptRecv = $dir . '/worker-recv.php';
		file_put_contents($scriptClose, $closeWorker);
		file_put_contents($scriptRecv, $recvWorker);

		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];
		$pClose = proc_open('php ' . escapeshellarg($scriptClose), $descriptors, $pipesClose, null, null);
		$pRecv = proc_open('php ' . escapeshellarg($scriptRecv), $descriptors, $pipesRecv, null, null);
		$this->assertIsResource($pClose);
		$this->assertIsResource($pRecv);
		foreach (array_merge($pipesClose, $pipesRecv) as $pipe) {
			fclose($pipe);
		}

		usleep(150000);
		file_put_contents($goFile, '1');

		proc_close($pClose);
		proc_close($pRecv);
		$outClose = is_file($resultClose) ? trim((string)file_get_contents($resultClose)) : 'missing';
		$outRecv = is_file($resultRecv) ? trim((string)file_get_contents($resultRecv)) : 'missing';

		foreach ([$scriptClose, $scriptRecv, $resultClose, $resultRecv, $goFile] as $f) {
			if (is_file($f)) {
				unlink($f);
			}
		}
		@rmdir($dir);

		self::assertSame('received', $outRecv, "receive worker failed: $outRecv");
		self::assertContains($outClose, ['closed', 'count_conflict'], "unexpected close result: $outClose");

		$bal = $balances->findPair($itemId, $locId);
		self::assertNotNull($bal);
		// Receive of +2 must survive: never wiped back to the frozen count of 10.
		self::assertSame(12, $bal->getQty(), "close raced receive and wiped stock; close=$outClose recv=$outRecv");
	}
}
