<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Db\CycleLineMapper;
use OCA\InventoryCheck\Db\Item;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\LocationMapper;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\Clock;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\LocationAclService;
use OCA\InventoryCheck\Service\MoneyVisibility;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

/**
 * IV-TOTAL-STOCK (spec total-stock-overview-2026-11): totalQty = SUM(qty)
 * over actor-visible locations — present for field users (NN-03), never a
 * global sum for ACL-scoped actors (NN-01).
 */
final class ItemServiceTotalQtyTest extends TestCase
{
	private function item(int $id): Item
	{
		$item = new Item();
		$item->setId($id);
		$item->setSku('SKU-' . $id);
		$item->setName('Item ' . $id);
		return $item;
	}

	/**
	 * @param list<int>|null $visible
	 * @param array<int, int> $sums
	 */
	private function service(
		ItemMapper $items,
		BalanceMapper $balances,
		?array $visible,
		bool $isOffice = true,
	): ItemService {
		$acl = $this->createMock(LocationAclService::class);
		$acl->method('visibleLocationIds')->willReturn($visible);
		$access = $this->createMock(AccessControlService::class);
		$access->method('isOffice')->willReturn($isOffice);
		return new ItemService(
			$this->createMock(IDBConnection::class),
			$items,
			$balances,
			$access,
			$this->createMock(Clock::class),
			$this->createMock(ILockingProvider::class),
			$acl,
			$this->createMock(IConfig::class),
			$this->createMock(CycleLineMapper::class),
			$this->createMock(LocationMapper::class),
		);
	}

	public function testListAddsServerSummedTotalQtyPerRow(): void
	{
		$items = $this->createMock(ItemMapper::class);
		$items->method('search')->willReturn([
			'data' => [$this->item(7), $this->item(8)],
			'total' => 2,
		]);
		$balances = $this->createMock(BalanceMapper::class);
		$balances->expects(self::once())->method('sumQtyByItem')
			->with(null)
			->willReturn([7 => 12]);

		$out = $this->service($items, $balances, null)->list('office', '', null, false, 50, 0);

		self::assertSame(12, $out['data'][0]['totalQty']);
		self::assertSame(0, $out['data'][1]['totalQty'], 'unstocked item renders 0, never blank (AC-1)');
	}

	public function testListSumsOnlyActorVisibleLocations(): void
	{
		$items = $this->createMock(ItemMapper::class);
		$items->method('search')->willReturn([
			'data' => [$this->item(7)],
			'total' => 1,
		]);
		$balances = $this->createMock(BalanceMapper::class);
		$balances->expects(self::once())->method('sumQtyByItem')
			->with([5])
			->willReturn([7 => 5]);

		$out = $this->service($items, $balances, [5], false)->list('field', '', null, false, 50, 0);

		self::assertSame(5, $out['data'][0]['totalQty'], 'NN-01: scoped sum, never the global 104');
	}

	public function testListFieldUserPayloadRetainsTotalQty(): void
	{
		$items = $this->createMock(ItemMapper::class);
		$items->method('search')->willReturn([
			'data' => [$this->item(7)],
			'total' => 1,
		]);
		$balances = $this->createMock(BalanceMapper::class);
		$balances->method('sumQtyByItem')->willReturn([7 => 5]);

		$out = $this->service($items, $balances, [5], false)->list('field', '', null, false, 50, 0);

		self::assertArrayHasKey('totalQty', $out['data'][0], 'NN-03: quantity is not money — never stripped');
	}

	public function testListNoGrantsYieldsZeroTotal(): void
	{
		$items = $this->createMock(ItemMapper::class);
		$items->method('search')->willReturn([
			'data' => [$this->item(7)],
			'total' => 1,
		]);
		$balances = $this->createMock(BalanceMapper::class);
		$balances->expects(self::once())->method('sumQtyByItem')
			->with([])
			->willReturn([]);

		$out = $this->service($items, $balances, [], false)->list('field', '', null, false, 50, 0);

		self::assertSame(0, $out['data'][0]['totalQty'], 'AC-9: empty grant set → 0');
	}

	public function testGetAddsScopedTotalQty(): void
	{
		$items = $this->createMock(ItemMapper::class);
		$items->method('findById')->with(7)->willReturn($this->item(7));
		$balances = $this->createMock(BalanceMapper::class);
		$balances->expects(self::once())->method('sumQtyForItem')
			->with(7, [5])
			->willReturn(5);

		$out = $this->service($items, $balances, [5], false)->get('field', 7);

		self::assertSame(5, $out['totalQty']);
	}

	public function testByCodeCarriesTotalQtyForMobile(): void
	{
		$items = $this->createMock(ItemMapper::class);
		$items->method('resolveByCode')->with('ABC')->willReturn($this->item(9));
		$balances = $this->createMock(BalanceMapper::class);
		$balances->expects(self::once())->method('sumQtyForItem')
			->with(9, [5])
			->willReturn(33);
		$balances->method('search')->willReturn(['data' => [], 'total' => 0]);

		$out = $this->service($items, $balances, [5], false)->byCodeRaw('field', 'ABC');

		self::assertSame(33, $out['totalQty'], 'mobile by-code inherits the field, actor-scoped (spec §9, NN-01)');
	}

	public function testMoneyVisibilityKeepsTotalQtyForFieldUsers(): void
	{
		$item = ['id' => 7, 'totalQty' => 5, 'lastPriceMinor' => 100, 'supplierName' => 'Acme'];
		$out = MoneyVisibility::itemForWeb($item, false);
		self::assertSame(5, $out['totalQty']);
		self::assertArrayNotHasKey('lastPriceMinor', $out);
	}
}
