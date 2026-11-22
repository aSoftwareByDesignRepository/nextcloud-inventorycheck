<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Db\Item;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\Lot;
use OCA\InventoryCheck\Db\LotMapper;
use OCA\InventoryCheck\Db\MovementMapper;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Service\Clock;
use OCA\InventoryCheck\Service\LocationAclService;
use OCA\InventoryCheck\Service\LotService;
use OCA\InventoryCheck\Service\RestockConfig;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * IV-LOT-EXPIRY-PACK: FEFO sort (NULLS-LAST), state classification at the
 * spec boundaries (§9), qty scoping, threshold-off semantics.
 */
final class LotServiceTest extends TestCase
{
	private const NOW = 1_800_000_000;
	private const DAY = 86400;

	private function lot(int $id, string $code, ?int $expiresAt, int $itemId = 7): Lot
	{
		$lot = new Lot();
		$lot->setId($id);
		$lot->setItemId($itemId);
		$lot->setLotCode($code);
		$lot->setExpiresAt($expiresAt);
		return $lot;
	}

	private function service(
		array $lots,
		array $qtyByCode,
		?array $visible = null,
		int $warnDays = 30,
		?MovementMapper $movements = null,
	): LotService {
		$lotMapper = $this->createMock(LotMapper::class);
		$lotMapper->method('findByItem')->willReturn($lots);
		$movements ??= $this->createMock(MovementMapper::class);
		$movements->method('sumQtyDeltaByLotForItem')->willReturn($qtyByCode);
		$items = $this->createMock(ItemMapper::class);
		$item = new Item();
		$item->setId(7);
		$items->method('findById')->willReturn($item);
		$acl = $this->createMock(LocationAclService::class);
		$acl->method('visibleLocationIds')->willReturn($visible);
		$restock = $this->restockWithDays($warnDays);
		$clock = $this->createMock(Clock::class);
		$clock->method('now')->willReturn(self::NOW);
		return new LotService($lotMapper, $movements, $items, $acl, $restock, $clock);
	}


	private function restockWithDays(int $days): RestockConfig
	{
		$cfg = $this->createMock(\OCP\IConfig::class);
		$cfg->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => $key === RestockConfig::KEY_EXPIRY_WARN_DAYS ? (string)$days : $default,
		);
		return new RestockConfig($cfg);
	}

	public function testFefoSortsEarliestExpiryFirstNullLast(): void
	{
		$svc = $this->service(
			[
				$this->lot(1, 'L-NULL', null),
				$this->lot(2, 'L-LATE', self::NOW + 40 * self::DAY),
				$this->lot(3, 'L-EARLY', self::NOW + 5 * self::DAY),
			],
			['L-NULL' => 4, 'L-LATE' => 4, 'L-EARLY' => 4],
		);
		$rows = $svc->lotsWithStock('office', 7);
		self::assertSame(['L-EARLY', 'L-LATE', 'L-NULL'], array_column($rows, 'lotCode'));
	}

	public function testEmptyLotsSortLast(): void
	{
		$svc = $this->service(
			[$this->lot(1, 'L-DEAD', self::NOW + 5 * self::DAY), $this->lot(2, 'L-LIVE', null)],
			['L-DEAD' => 0, 'L-LIVE' => 3],
		);
		$rows = $svc->lotsWithStock('office', 7);
		self::assertSame('L-LIVE', $rows[0]['lotCode']);
		self::assertSame('empty', $rows[1]['state']);
	}

	public function testMovementLotWithoutLotRowStaysPickable(): void
	{
		$svc = $this->service([], ['L-GHOST' => 9]);
		$rows = $svc->lotsWithStock('office', 7);
		self::assertSame('L-GHOST', $rows[0]['lotCode']);
		self::assertSame(9, $rows[0]['qty']);
		self::assertSame('ok', $rows[0]['state']);
	}

	public function testAclScopeIsPassedToAggregate(): void
	{
		$movements = $this->createMock(MovementMapper::class);
		$movements->expects(self::once())->method('sumQtyDeltaByLotForItem')
			->with(7, [3, 9])
			->willReturn(['L1' => 2]);
		$svc = $this->service([$this->lot(1, 'L1', null)], ['L1' => 2], [3, 9], 30, $movements);
		$svc->lotsWithStock('field', 7);
	}

	public function testInaccessibleOnlyLocationIsUniform404(): void
	{
		$svc = $this->service([], [], [3, 9]);
		$this->expectException(NotFoundException::class);
		$svc->lotsWithStock('field', 7, 55);
	}

	public function testAccessibleOnlyLocationNarrowsScope(): void
	{
		$movements = $this->createMock(MovementMapper::class);
		$movements->expects(self::once())->method('sumQtyDeltaByLotForItem')
			->with(7, [9])
			->willReturn(['L1' => 6]);
		$svc = $this->service([$this->lot(1, 'L1', null)], ['L1' => 6], [3, 9], 30, $movements);
		$rows = $svc->lotsWithStock('field', 7, 9);
		self::assertSame(6, $rows[0]['qty']);
	}

	public function testUnknownItemIs404(): void
	{
		$items = $this->createMock(ItemMapper::class);
		$items->method('findById')->willThrowException(new DoesNotExistException('x'));
		$lotMapper = $this->createMock(LotMapper::class);
		$acl = $this->createMock(LocationAclService::class);
		$restock = $this->restockWithDays(30);
		$clock = $this->createMock(Clock::class);
		$svc = new LotService(
			$lotMapper,
			$this->createMock(MovementMapper::class),
			$items,
			$acl,
			$restock,
			$clock,
		);
		$this->expectException(NotFoundException::class);
		$svc->lotsWithStock('office', 999);
	}

	// --- state classification boundaries (spec §9) ---

	public function testExpiredBoundaryIsStrictlyBeforeNow(): void
	{
		$warn = 30 * self::DAY;
		// end-of-day epoch: expiry == now is still good, not expired
		self::assertSame('expiring', LotService::classify(1, self::NOW, self::NOW, $warn));
		self::assertSame('expired', LotService::classify(1, self::NOW - 1, self::NOW, $warn));
	}

	public function testExpiringThresholdInclusive(): void
	{
		$warn = 30 * self::DAY;
		self::assertSame('expiring', LotService::classify(1, self::NOW + $warn, self::NOW, $warn));
		self::assertSame('ok', LotService::classify(1, self::NOW + $warn + 1, self::NOW, $warn));
	}

	public function testWarnZeroDisablesWatchNotSorting(): void
	{
		self::assertSame('ok', LotService::classify(1, self::NOW + self::DAY, self::NOW, 0));
		// still expired when past
		self::assertSame('expired', LotService::classify(1, self::NOW - 1, self::NOW, 0));
	}

	public function testEmptyWinsOverExpiryStates(): void
	{
		$warn = 30 * self::DAY;
		self::assertSame('empty', LotService::classify(0, self::NOW - 1, self::NOW, $warn));
		self::assertSame('empty', LotService::classify(-3, self::NOW + self::DAY, self::NOW, $warn));
	}

	public function testNullExpiryIsNeverExpiring(): void
	{
		$warn = 30 * self::DAY;
		self::assertSame('ok', LotService::classify(5, null, self::NOW, $warn));
	}

	public function testExpiresInDaysFloor(): void
	{
		$svc = $this->service(
			[$this->lot(1, 'L', self::NOW + 5 * self::DAY + 3600)],
			['L' => 2],
		);
		$rows = $svc->lotsWithStock('office', 7);
		self::assertSame(5, $rows[0]['expiresInDays']);
	}

	// --- watch card ---

	public function testExpiringSoonSkipsZeroQtyAndOffWatch(): void
	{
		$lotMapper = $this->createMock(LotMapper::class);
		$lotMapper->method('expiringWithin')->willReturn([
			$this->lot(1, 'L-DEAD', self::NOW + self::DAY),
			$this->lot(2, 'L-LIVE', self::NOW + self::DAY),
		]);
		$movements = $this->createMock(MovementMapper::class);
		$movements->method('sumQtyDeltaByItemLotMap')->willReturn([
			7 => ['L-DEAD' => 0, 'L-LIVE' => 3],
		]);
		$items = $this->createMock(ItemMapper::class);
		$item = new Item();
		$item->setId(7);
		$item->setName('Thing');
		$item->setSku('SKU7');
		$items->method('findById')->willReturn($item);
		$acl = $this->createMock(LocationAclService::class);
		$acl->method('visibleLocationIds')->willReturn(null);
		$restock = $this->restockWithDays(30);
		$clock = $this->createMock(Clock::class);
		$clock->method('now')->willReturn(self::NOW);
		$svc = new LotService($lotMapper, $movements, $items, $acl, $restock, $clock);

		$rows = $svc->expiringSoon('office');
		self::assertCount(1, $rows);
		self::assertSame('L-LIVE', $rows[0]['lotCode']);
		self::assertSame('Thing', $rows[0]['itemName']);
		self::assertSame('expiring', $rows[0]['state']);
	}

	public function testExpiringSoonReturnsEmptyWhenWatchOff(): void
	{
		$lotMapper = $this->createMock(LotMapper::class);
		$lotMapper->expects(self::never())->method('expiringWithin');
		$restock = $this->restockWithDays(0);
		$svc = new LotService(
			$lotMapper,
			$this->createMock(MovementMapper::class),
			$this->createMock(ItemMapper::class),
			$this->createMock(LocationAclService::class),
			$restock,
			$this->createMock(Clock::class),
		);
		self::assertSame([], $svc->expiringSoon('office'));
	}

	public function testExpiringSoonMarksExpiredRows(): void
	{
		$lotMapper = $this->createMock(LotMapper::class);
		$lotMapper->method('expiringWithin')->willReturn([
			$this->lot(1, 'L-OLD', self::NOW - 2 * self::DAY),
		]);
		$movements = $this->createMock(MovementMapper::class);
		$movements->method('sumQtyDeltaByItemLotMap')->willReturn([7 => ['L-OLD' => 1]]);
		$items = $this->createMock(ItemMapper::class);
		$item = new Item();
		$item->setId(7);
		$items->method('findById')->willReturn($item);
		$acl = $this->createMock(LocationAclService::class);
		$acl->method('visibleLocationIds')->willReturn(null);
		$restock = $this->restockWithDays(30);
		$clock = $this->createMock(Clock::class);
		$clock->method('now')->willReturn(self::NOW);
		$svc = new LotService($lotMapper, $movements, $items, $acl, $restock, $clock);

		$rows = $svc->expiringSoon('office');
		self::assertSame('expired', $rows[0]['state']);
		self::assertLessThan(0, $rows[0]['expiresInDays']);
	}
}
