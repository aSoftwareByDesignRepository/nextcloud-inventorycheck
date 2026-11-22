<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Db\Item;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\Location;
use OCA\InventoryCheck\Db\LocationMapper;
use OCA\InventoryCheck\Db\MovementMapper;
use OCA\InventoryCheck\Service\Clock;
use OCA\InventoryCheck\Service\ReportService;
use PHPUnit\Framework\TestCase;

/**
 * IV-REPORTS-SURFACE: dead-stock window boundaries (any-movement clock,
 * young-item exclusion, unpriced counting) and per-location value math.
 */
final class ReportServiceTest extends TestCase
{
	private const NOW = 1_800_000_000;
	private const DAY = 86400;

	private function item(int $id, string $sku, ?int $priceMinor, int $createdAt): Item
	{
		$item = new Item();
		$item->setId($id);
		$item->setSku($sku);
		$item->setName('Item ' . $sku);
		$item->setLastPriceMinor($priceMinor);
		$item->setCreatedAt($createdAt);
		return $item;
	}

	private function location(int $id, string $code): Location
	{
		$loc = new Location();
		$loc->setId($id);
		$loc->setCode($code);
		$loc->setName('Loc ' . $code);
		return $loc;
	}

	private function service(array $items, array $sums, array $lastMove, array $matrix = [], array $locations = []): ReportService
	{
		$itemsMapper = $this->createMock(ItemMapper::class);
		$itemsMapper->method('iterateActive')->willReturnCallback(
			static function () use ($items): \Generator {
				yield from $items;
			});
		$balances = $this->createMock(BalanceMapper::class);
		$balances->method('sumQtyByItem')->willReturn($sums);
		$balances->method('sumQtyByItemAndLocation')->willReturn($matrix);
		$movements = $this->createMock(MovementMapper::class);
		$movements->method('lastMovementByItem')->willReturn($lastMove);
		$locMapper = $this->createMock(LocationMapper::class);
		$locMapper->method('search')->willReturn(['data' => $locations, 'total' => count($locations)]);
		$clock = $this->createMock(Clock::class);
		$clock->method('now')->willReturn(self::NOW);
		return new ReportService($itemsMapper, $balances, $movements, $locMapper, $clock);
	}

	public function testDeadStockListsOnlyUntouchedItems(): void
	{
		$old = self::NOW - 200 * self::DAY;
		$recent = self::NOW - 10 * self::DAY;
		$items = [
			$this->item(1, 'DEAD', 500, $old),          // untouched 200d, priced
			$this->item(2, 'FRESH', 500, $recent),      // created 10d ago, never moved
			$this->item(3, 'MOVED', 500, $old),         // old item but moved 5d ago
			$this->item(4, 'EMPTY', 500, $old),         // zero stock
			$this->item(5, 'NOPRICE', null, $old),      // untouched, unpriced
		];
		$svc = $this->service(
			$items,
			[1 => 10, 2 => 10, 3 => 10, 4 => 0, 5 => 7],
			[3 => self::NOW - 5 * self::DAY],
		);
		$report = $svc->deadStock(90, 1, self::NOW);
		$this->assertTrue($report['isEstimate']);
		$this->assertSame(90, $report['days']);
		$skus = array_column($report['rows'], 'sku');
		$this->assertSame(['DEAD', 'NOPRICE'], $skus);
		$this->assertSame(1, $report['unpricedCount']);
		$dead = $report['rows'][0];
		$this->assertSame($old, $dead['lastActivityAt']);
		$this->assertSame(5000, $dead['valueMinor']); // 10 × 500 @ factor 1
		$this->assertNull($report['rows'][1]['valueMinor']);
	}

	public function testDeadStockAnyMovementResetsClock(): void
	{
		$old = self::NOW - 200 * self::DAY;
		// Item created long ago; last movement exactly at the threshold
		// boundary — any movement, not just issue, counts (AS-01).
		$items = [$this->item(1, 'EDGE', 100, $old)];
		$svc = $this->service($items, [1 => 3], [1 => self::NOW - 90 * self::DAY]);
		$report = $svc->deadStock(90, 1, self::NOW);
		$this->assertSame([], $report['rows']);
		// One second older and it qualifies.
		$svc2 = $this->service($items, [1 => 3], [1 => self::NOW - 90 * self::DAY - 1]);
		$this->assertCount(1, $svc2->deadStock(90, 1, self::NOW)['rows']);
	}

	public function testDeadStockNeverMovedUsesCreatedAt(): void
	{
		$old = self::NOW - 400 * self::DAY;
		$items = [$this->item(1, 'ANCIENT', null, $old)];
		$svc = $this->service($items, [1 => 2], []);
		$report = $svc->deadStock(365, 1, self::NOW);
		$this->assertCount(1, $report['rows']);
		$this->assertSame($old, $report['rows'][0]['lastActivityAt']);
		$this->assertSame(1, $report['unpricedCount']);
	}

	public function testDeadStockSortedOldestFirst(): void
	{
		$items = [
			$this->item(1, 'NEWER-DEAD', 100, self::NOW - 100 * self::DAY),
			$this->item(2, 'OLDER-DEAD', 100, self::NOW - 300 * self::DAY),
		];
		$svc = $this->service($items, [1 => 1, 2 => 1], []);
		$rows = $svc->deadStock(90, 1, self::NOW)['rows'];
		$this->assertSame(['OLDER-DEAD', 'NEWER-DEAD'], array_column($rows, 'sku'));
	}

	public function testLocationValueAggregatesAndCountsUnpricedOnHand(): void
	{
		$items = [
			$this->item(1, 'A', 1000, self::NOW - 100 * self::DAY),
			$this->item(2, 'B', null, self::NOW - 100 * self::DAY),
			$this->item(3, 'C', 500, self::NOW - 100 * self::DAY),
			$this->item(4, 'D-NO-STOCK', null, self::NOW - 100 * self::DAY),
		];
		$matrix = [
			1 => [10 => 4, 20 => 6],   // A: 4@loc10 + 6@loc20
			2 => [10 => 3],            // B unpriced @loc10
			3 => [20 => 0, 10 => -2],  // C: no positive qty anywhere
		];
		$locs = [$this->location(10, 'L10'), $this->location(20, 'L20')];
		$svc = $this->service($items, [], [], $matrix, $locs);
		$report = $svc->locationValue(1);
		$this->assertTrue($report['isEstimate']);
		$byCode = [];
		foreach ($report['rows'] as $row) {
			$byCode[$row['code']] = $row;
		}
		// loc10: A(4×1000) + B(unpriced) → 4000, 2 items
		$this->assertSame(4000, $byCode['L10']['valueMinor']);
		$this->assertSame(2, $byCode['L10']['itemCount']);
		// loc20: A(6×1000) → 6000
		$this->assertSame(6000, $byCode['L20']['valueMinor']);
		$this->assertSame(10000, $report['totalMinor']);
		// Only B counts as unpriced-on-hand (D has no stock, C none positive).
		$this->assertSame(1, $report['unpricedCount']);
		// Sorted by value desc.
		$this->assertSame('L20', $report['rows'][0]['code']);
	}

	public function testLocationValueExcludesNonPositiveQty(): void
	{
		// Zero balance rows must not count a location as holding stock —
		// killing the `$qty < 0` mutant that admits them.
		$items = [$this->item(1, 'ZERO', 100, self::NOW - 100 * self::DAY)];
		$matrix = [1 => [10 => 0]];
		$locs = [$this->location(10, 'L10')];
		$svc = $this->service($items, [], [], $matrix, $locs);
		$report = $svc->locationValue(1);
		$this->assertSame([], $report['rows']);
		$this->assertSame(0, $report['unpricedCount']);
	}

	public function testLocationValueEmptyWhenNoStock(): void
	{
		$svc = $this->service([], [], [], [], []);
		$report = $svc->locationValue(1);
		$this->assertSame([], $report['rows']);
		$this->assertSame(0, $report['totalMinor']);
		$this->assertSame(0, $report['unpricedCount']);
	}
}
