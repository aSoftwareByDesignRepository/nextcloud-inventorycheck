<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Exception\ConflictException;
use OCA\InventoryCheck\Exception\InsufficientStockException;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\LowStockService;
use OCA\InventoryCheck\Service\MovementService;
use OCP\IConfig;
use OCP\Server;
use Test\TestCase;

/**
 * Lifecycle / by-code / low-stock / race-serialisation (AC-8…AC-11, E3–E8).
 *
 * @group DB
 */
final class LifecycleAndRaceIntegrationTest extends TestCase
{
	private MovementService $movements;
	private LocationService $locations;
	private ItemService $items;
	private LowStockService $lowStock;
	private BalanceMapper $balances;
	private string $uid = 'admin';

	protected function setUp(): void
	{
		parent::setUp();
		$app = new Application();
		$c = $app->getContainer();
		$this->movements = $c->get(MovementService::class);
		$this->locations = $c->get(LocationService::class);
		$this->items = $c->get(ItemService::class);
		$this->lowStock = $c->get(LowStockService::class);
		$this->balances = $c->get(BalanceMapper::class);

		$config = Server::get(IConfig::class);
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ALLOW_NEGATIVE, '0');
	}

	public function testByCodeResolvesScanCodeThenSkuAndInactiveIsMiss(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$item = $this->items->create($this->uid, [
			'sku' => 'BYSKU-' . $suffix,
			'scanCode' => 'BYSCAN-' . $suffix,
			'name' => 'By-code item',
		]);
		$loc = $this->locations->create($this->uid, [
			'code' => 'BYLOC-' . $suffix,
			'name' => 'Loc',
			'kind' => 'shelf',
		]);
		$this->movements->receive($this->uid, (int)$item['id'], (int)$loc['id'], 2, null);

		$byScan = $this->items->byCode($this->uid, 'BYSCAN-' . $suffix);
		$this->assertSame((int)$item['id'], (int)$byScan['id']);
		$this->assertNotEmpty($byScan['balances']);

		$bySku = $this->items->byCode($this->uid, 'BYSKU-' . $suffix);
		$this->assertSame((int)$item['id'], (int)$bySku['id']);

		$this->movements->adjust($this->uid, (int)$item['id'], (int)$loc['id'], 'set', 0, null, 'zero');
		$this->items->update($this->uid, (int)$item['id'], ['active' => false]);

		$this->expectException(NotFoundException::class);
		$this->items->byCode($this->uid, 'BYSCAN-' . $suffix);
	}

	public function testDuplicateAndCrossFieldCodesConflict(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$this->items->create($this->uid, [
			'sku' => 'C1-' . $suffix,
			'scanCode' => 'S1-' . $suffix,
			'name' => 'One',
		]);

		try {
			$this->items->create($this->uid, [
				'sku' => 'C1-' . $suffix,
				'name' => 'Dup sku',
			]);
			$this->fail('expected ConflictException for duplicate sku');
		} catch (ConflictException $e) {
			$this->assertSame('code_exists', $e->getErrorCode());
		}

		try {
			$this->items->create($this->uid, [
				'sku' => 'C2-' . $suffix,
				'scanCode' => 'C1-' . $suffix,
				'name' => 'Cross field',
			]);
			$this->fail('expected ConflictException for scan=other sku');
		} catch (ConflictException $e) {
			$this->assertSame('code_exists', $e->getErrorCode());
		}
	}

	public function testInvalidCodeCharsetRejected(): void
	{
		$this->expectException(ValidationException::class);
		$this->items->create($this->uid, [
			'sku' => 'BAD CODE',
			'name' => 'Nope',
		]);
	}

	public function testDeactivateBlockedWithStockAndDeleteBlockedWithMovements(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$loc = $this->locations->create($this->uid, [
			'code' => 'DL-' . $suffix,
			'name' => 'Loc',
			'kind' => 'other',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => 'DI-' . $suffix,
			'name' => 'Item',
		]);
		$itemId = (int)$item['id'];
		$locId = (int)$loc['id'];
		$this->movements->receive($this->uid, $itemId, $locId, 3, null);

		try {
			$this->items->update($this->uid, $itemId, ['active' => false]);
			$this->fail('expected item_has_stock');
		} catch (ConflictException $e) {
			$this->assertSame('item_has_stock', $e->getErrorCode());
		}

		try {
			$this->locations->update($this->uid, $locId, ['active' => false]);
			$this->fail('expected location_has_stock');
		} catch (ConflictException $e) {
			$this->assertSame('location_has_stock', $e->getErrorCode());
		}

		try {
			$this->items->delete($this->uid, $itemId);
			$this->fail('expected item_has_movements');
		} catch (ConflictException $e) {
			$this->assertSame('item_has_movements', $e->getErrorCode());
		}

		try {
			$this->locations->delete($this->uid, $locId);
			$this->fail('expected location_has_movements');
		} catch (ConflictException $e) {
			$this->assertSame('location_has_movements', $e->getErrorCode());
		}
	}

	public function testInactiveItemMovementRejected(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$loc = $this->locations->create($this->uid, [
			'code' => 'IL-' . $suffix,
			'name' => 'Loc',
			'kind' => 'other',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => 'II-' . $suffix,
			'name' => 'Item',
		]);
		$this->items->update($this->uid, (int)$item['id'], ['active' => false]);
		$this->expectException(ValidationException::class);
		$this->movements->receive($this->uid, (int)$item['id'], (int)$loc['id'], 1, null);
	}

	public function testLowStockAppearsWhenBelowReorder(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$loc = $this->locations->create($this->uid, [
			'code' => 'LS-' . $suffix,
			'name' => 'Loc',
			'kind' => 'other',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => 'LSI-' . $suffix,
			'name' => 'Filter',
			'reorderLevel' => 5,
		]);
		$itemId = (int)$item['id'];
		$this->movements->receive($this->uid, $itemId, (int)$loc['id'], 4, null);

		// Fetch the full low-stock result set (prior test leftovers can fill page 1).
		$first = $this->lowStock->list($this->uid, 200, 0);
		$total = (int)$first['total'];
		$ids = array_map(static fn (array $r): int => (int)$r['item']['id'], $first['data']);
		for ($offset = 200; $offset < $total; $offset += 200) {
			$page = $this->lowStock->list($this->uid, 200, $offset);
			foreach ($page['data'] as $row) {
				$ids[] = (int)$row['item']['id'];
			}
		}
		$this->assertContains($itemId, $ids);

		// SPEC §7.3: GET /api/items?lowStock= filters the item list the same way.
		$filtered = $this->items->list($this->uid, '', null, true, 200, 0);
		$filteredIds = array_map(static fn (array $r): int => (int)$r['id'], $filtered['data']);
		$filteredTotal = (int)$filtered['total'];
		for ($offset = 200; $offset < $filteredTotal; $offset += 200) {
			$page = $this->items->list($this->uid, '', null, true, 200, $offset);
			foreach ($page['data'] as $row) {
				$filteredIds[] = (int)$row['id'];
			}
		}
		$this->assertContains($itemId, $filteredIds);

		// Replenish above the reorder level → drops off the filtered list.
		$this->movements->receive($this->uid, $itemId, (int)$loc['id'], 10, null);
		$refreshed = $this->items->list($this->uid, '', null, true, max(200, $filteredTotal), 0);
		$refreshedIds = array_map(static fn (array $r): int => (int)$r['id'], $refreshed['data']);
		$this->assertNotContains($itemId, $refreshedIds);
	}

	public function testSerialisedLastUnitRaceSecondIssueFails(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$loc = $this->locations->create($this->uid, [
			'code' => 'RC-' . $suffix,
			'name' => 'Loc',
			'kind' => 'other',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => 'RI-' . $suffix,
			'name' => 'Item',
		]);
		$itemId = (int)$item['id'];
		$locId = (int)$loc['id'];
		$this->movements->receive($this->uid, $itemId, $locId, 5, null);

		$this->movements->issue($this->uid, $itemId, $locId, 5, 'first takes all');
		$this->assertSame(0, $this->balances->findPair($itemId, $locId)?->getQty());

		$this->expectException(InsufficientStockException::class);
		$this->movements->issue($this->uid, $itemId, $locId, 1, 'second must fail');
	}

	public function testAllowNegativeIssueBelowZero(): void
	{
		$config = Server::get(IConfig::class);
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ALLOW_NEGATIVE, '1');
		try {
			$suffix = bin2hex(random_bytes(3));
			$loc = $this->locations->create($this->uid, [
				'code' => 'NG-' . $suffix,
				'name' => 'Loc',
				'kind' => 'other',
			]);
			$item = $this->items->create($this->uid, [
				'sku' => 'NI-' . $suffix,
				'name' => 'Item',
			]);
			$itemId = (int)$item['id'];
			$locId = (int)$loc['id'];
			$this->movements->receive($this->uid, $itemId, $locId, 1, null);
			$res = $this->movements->issue($this->uid, $itemId, $locId, 3, 'overdraw');
			$this->assertSame(-2, $res['balances'][0]['qty']);
			$this->assertSame(-2, $res['movements'][0]['qtyAfter']);
		} finally {
			$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ALLOW_NEGATIVE, '0');
		}
	}

	public function testScanIssueByCode(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$loc = $this->locations->create($this->uid, [
			'code' => 'SC-' . $suffix,
			'name' => 'Loc',
			'kind' => 'van',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => 'SI-' . $suffix,
			'scanCode' => 'SCAN-' . $suffix,
			'name' => 'Scan me',
		]);
		$itemId = (int)$item['id'];
		$locId = (int)$loc['id'];
		$this->movements->receive($this->uid, $itemId, $locId, 4, null);
		$res = $this->movements->scan(
			$this->uid,
			'SCAN-' . $suffix,
			'issue',
			$locId,
			null,
			2,
			null,
			'scan issue',
			true,
		);
		$this->assertSame(2, $res['balances'][0]['qty']);
	}

	public function testZeroQtyRejected(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$loc = $this->locations->create($this->uid, [
			'code' => 'ZQ-' . $suffix,
			'name' => 'Loc',
			'kind' => 'other',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => 'ZI-' . $suffix,
			'name' => 'Item',
		]);
		$this->expectException(ValidationException::class);
		$this->movements->receive($this->uid, (int)$item['id'], (int)$loc['id'], 0, null);
	}
}
