<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\BalanceService;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\MovementService;
use OCP\IConfig;
use OCP\Server;
use Test\TestCase;

/**
 * AC-14 / S4 — unknown masters + negative balance listing.
 *
 * @group DB
 */
final class UnknownAndNegativeBalancesIntegrationTest extends TestCase
{
	private MovementService $movements;
	private LocationService $locations;
	private ItemService $items;
	private BalanceService $balances;
	private string $uid = 'admin';

	protected function setUp(): void
	{
		parent::setUp();
		$app = new Application();
		$c = $app->getContainer();
		$this->movements = $c->get(MovementService::class);
		$this->locations = $c->get(LocationService::class);
		$this->items = $c->get(ItemService::class);
		$this->balances = $c->get(BalanceService::class);
		Server::get(IConfig::class)->setAppValue(
			Application::APP_ID,
			AccessControlService::KEY_ALLOW_NEGATIVE,
			'1',
		);
	}

	public function testUnknownItemIdReturnsUnknownItem(): void
	{
		$loc = $this->locations->create($this->uid, [
			'code' => 'U-LOC-' . bin2hex(random_bytes(3)),
			'name' => 'Unknown item loc',
			'kind' => 'other',
		]);
		try {
			$this->movements->receive($this->uid, 999999991, (int)$loc['id'], 1, null);
			$this->fail('expected NotFoundException');
		} catch (NotFoundException $e) {
			$this->assertSame('unknown_item', $e->getErrorCode());
		}
	}

	public function testUnknownLocationIdReturnsUnknownLocation(): void
	{
		$item = $this->items->create($this->uid, [
			'sku' => 'U-SKU-' . bin2hex(random_bytes(3)),
			'name' => 'Unknown loc item',
		]);
		try {
			$this->movements->receive($this->uid, (int)$item['id'], 999999992, 1, null);
			$this->fail('expected NotFoundException');
		} catch (NotFoundException $e) {
			$this->assertSame('unknown_location', $e->getErrorCode());
		}
	}

	public function testNegativeBalanceFilterReturnsOnlyNegativeQtys(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$loc = $this->locations->create($this->uid, [
			'code' => 'N-LOC-' . $suffix,
			'name' => 'Neg loc',
			'kind' => 'van',
		]);
		$itemNeg = $this->items->create($this->uid, [
			'sku' => 'N-SKU-' . $suffix,
			'name' => 'Neg item',
		]);
		$itemPos = $this->items->create($this->uid, [
			'sku' => 'P-SKU-' . $suffix,
			'name' => 'Pos item',
		]);
		$locId = (int)$loc['id'];
		$this->movements->issue($this->uid, (int)$itemNeg['id'], $locId, 4, 'go neg');
		$this->movements->receive($this->uid, (int)$itemPos['id'], $locId, 5, 'seed');

		$neg = $this->balances->list(null, $locId, false, 50, 0, true);
		$this->assertGreaterThanOrEqual(1, $neg['total']);
		foreach ($neg['data'] as $row) {
			$this->assertLessThan(0, $row['qty'], 'negative filter must exclude non-negative rows');
		}
		$ids = array_map(static fn (array $r): int => (int)$r['itemId'], $neg['data']);
		$this->assertContains((int)$itemNeg['id'], $ids);
		$this->assertNotContains((int)$itemPos['id'], $ids);
	}

	public function testNonZeroFilterExcludesZeroBalances(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$locA = $this->locations->create($this->uid, [
			'code' => 'Z-A-' . $suffix,
			'name' => 'Zero A',
			'kind' => 'shelf',
		]);
		$locB = $this->locations->create($this->uid, [
			'code' => 'Z-B-' . $suffix,
			'name' => 'Zero B',
			'kind' => 'shelf',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => 'Z-SKU-' . $suffix,
			'name' => 'Zero item',
		]);
		$itemId = (int)$item['id'];
		$this->movements->receive($this->uid, $itemId, (int)$locA['id'], 3, 'seed');
		// Touch loc B then adjust back to zero so a zero row exists.
		$this->movements->receive($this->uid, $itemId, (int)$locB['id'], 2, 'tmp');
		$this->movements->adjust($this->uid, $itemId, (int)$locB['id'], 'set', 0, null, 'clear');

		$nonZero = $this->balances->list($itemId, null, true, 50, 0, false);
		$this->assertGreaterThanOrEqual(1, $nonZero['total']);
		foreach ($nonZero['data'] as $row) {
			$this->assertNotSame(0, $row['qty'], 'nonZero filter must exclude qty=0 rows');
		}
		$locIds = array_map(static fn (array $r): int => (int)$r['locationId'], $nonZero['data']);
		$this->assertContains((int)$locA['id'], $locIds);
		$this->assertNotContains((int)$locB['id'], $locIds);
	}

	public function testMovementListFiltersByKindAndItem(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$loc = $this->locations->create($this->uid, [
			'code' => 'F-LOC-' . $suffix,
			'name' => 'Filter loc',
			'kind' => 'warehouse',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => 'F-SKU-' . $suffix,
			'name' => 'Filter item',
		]);
		$itemId = (int)$item['id'];
		$locId = (int)$loc['id'];
		$this->movements->receive($this->uid, $itemId, $locId, 2, 'r');
		$this->movements->issue($this->uid, $itemId, $locId, 1, 'i');

		$issues = $this->movements->list('issue', $itemId, $locId, null, null, null, 50, 0);
		$this->assertSame(1, $issues['total']);
		$this->assertSame('issue', $issues['data'][0]['kind']);

		$recv = $this->movements->list('receive', $itemId, null, null, null, null, 50, 0);
		$this->assertGreaterThanOrEqual(1, $recv['total']);
		foreach ($recv['data'] as $row) {
			$this->assertSame('receive', $row['kind']);
			$this->assertSame($itemId, $row['itemId']);
		}
	}
}
