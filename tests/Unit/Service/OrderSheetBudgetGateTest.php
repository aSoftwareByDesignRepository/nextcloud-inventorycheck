<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Db\Item;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\MovementMapper;
use OCA\InventoryCheck\Db\OrderSheet;
use OCA\InventoryCheck\Db\OrderSheetLine;
use OCA\InventoryCheck\Db\OrderSheetLineMapper;
use OCA\InventoryCheck\Db\OrderSheetMapper;
use OCA\InventoryCheck\Db\SupplierMapper;
use OCA\InventoryCheck\Exception\ApprovalRequiredException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\Clock;
use OCA\InventoryCheck\Service\OrderSheetService;
use OCA\InventoryCheck\Service\RestockConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Budget-cycle send gate: order cost per category vs remaining
 * (budget − MTD received spend) and the owner emergency ceiling.
 * Second half: draft line replacement — the "adjust to fit" path.
 */
final class OrderSheetBudgetGateTest extends TestCase
{
	private IDBConnection&MockObject $db;
	private OrderSheetMapper&MockObject $sheets;
	private OrderSheetLineMapper&MockObject $lines;
	private ItemMapper&MockObject $items;
	private AccessControlService&MockObject $access;
	private RestockConfig $restock;
	private IConfig&MockObject $config;
	private MovementMapper&MockObject $movements;
	private OrderSheetService $svc;
	/** @var array<string, string> scripted app-config values for the real RestockConfig */
	private array $appValues = [];

	protected function setUp(): void
	{
		$this->db = $this->createMock(IDBConnection::class);
		$this->sheets = $this->createMock(OrderSheetMapper::class);
		$this->lines = $this->createMock(OrderSheetLineMapper::class);
		$this->items = $this->createMock(ItemMapper::class);
		$this->access = $this->createMock(AccessControlService::class);
		$this->config = $this->createMock(IConfig::class);
		$this->movements = $this->createMock(MovementMapper::class);
		$this->config->method('getAppValue')
			->willReturnCallback(fn (string $app, string $key, string $default = '') => $this->appValues[$key] ?? $default);
		$this->restock = new RestockConfig($this->config);
		$clock = $this->createMock(Clock::class);
		$clock->method('now')->willReturn(1700000000);
		$this->svc = new OrderSheetService(
			$this->db,
			$this->sheets,
			$this->lines,
			$this->items,
			$this->createMock(SupplierMapper::class),
			$this->access,
			$this->restock,
			$clock,
			$this->config,
			null,
			null,
			null,
			$this->movements,
		);
		$this->access->method('requireOffice');
	}

	/** @param array<string, int> $budgets */
	private function restockConfig(array $budgets, ?int $emergency): void
	{
		$this->appValues[RestockConfig::KEY_BUDGETS] = json_encode($budgets);
		$this->appValues[RestockConfig::KEY_EMERGENCY_BUDGET] = $emergency === null ? '' : (string)$emergency;
	}

	private function sheet(): OrderSheet
	{
		$s = new OrderSheet();
		$s->setId(7);
		$s->setStatus(OrderSheet::STATUS_DRAFT);
		return $s;
	}

	private function line(int $itemId, int $qty): OrderSheetLine
	{
		$l = new OrderSheetLine();
		$l->setSheetId(7);
		$l->setItemId($itemId);
		$l->setQty($qty);
		return $l;
	}

	private function item(int $id, ?string $category, ?int $unitPrice): Item
	{
		$i = new Item();
		$i->setId($id);
		$i->setCategory($category);
		$i->setLastPriceMinor($unitPrice);
		return $i;
	}

	public function testWithinRemainingSends(): void
	{
		$this->restockConfig(['CLIN' => 1000], 0);
		$this->movements->method('receivedSpendByCategorySince')->willReturn(['CLIN' => 200]);
		$this->sheets->method('findById')->willReturn($this->sheet());
		$this->lines->method('findBySheet')->willReturn([$this->line(1, 8)]); // est 800 = remaining
		$this->items->method('findById')->willReturn($this->item(1, 'CLIN', 100)); // 800/1000 qty → 800 minor? lineMinor: qty*price/factor=800
		$this->access->method('isAppAdmin')->willReturn(false);
		$this->sheets->expects($this->once())->method('setStatus');
		$this->svc->send('u1', 7);
	}

	public function testOverRemainingNeedsAdmin(): void
	{
		$this->restockConfig(['CLIN' => 1000], 0);
		$this->movements->method('receivedSpendByCategorySince')->willReturn(['CLIN' => 900]); // remaining 100
		$this->sheets->method('findById')->willReturn($this->sheet());
		$this->lines->method('findBySheet')->willReturn([$this->line(1, 5)]); // est 500 > 100
		$this->items->method('findById')->willReturn($this->item(1, 'CLIN', 100));
		$this->access->method('isAppAdmin')->willReturn(false);
		$this->expectException(ApprovalRequiredException::class);
		$this->svc->send('u1', 7);
	}

	public function testOverRemainingAdminIsAuthorization(): void
	{
		$this->restockConfig(['CLIN' => 1000], 0);
		$this->movements->method('receivedSpendByCategorySince')->willReturn(['CLIN' => 900]);
		$this->sheets->method('findById')->willReturn($this->sheet());
		$this->lines->method('findBySheet')->willReturn([$this->line(1, 5)]); // est 500 > 100
		$this->items->method('findById')->willReturn($this->item(1, 'CLIN', 100));
		$this->access->method('isAppAdmin')->willReturn(true); // owner send IS the authorization
		$this->sheets->expects($this->once())->method('setStatus');
		$this->svc->send('boss', 7);
	}

	public function testOverEmergencyHardBlockForEveryone(): void
	{
		$this->restockConfig(['CLIN' => 1000], 100);
		$this->movements->method('receivedSpendByCategorySince')->willReturn(['CLIN' => 900]); // remaining 100
		$this->sheets->method('findById')->willReturn($this->sheet());
		$this->lines->method('findBySheet')->willReturn([$this->line(1, 5)]); // est 500 > 100 // overage 400 > emergency 100
		$this->items->method('findById')->willReturn($this->item(1, 'CLIN', 100));
		$this->access->method('isAppAdmin')->willReturn(true);
		$this->expectException(ValidationException::class);
		$this->svc->send('boss', 7);
	}

	public function testOverWithinEmergencyStillNeedsAdmin(): void
	{
		$this->restockConfig(['CLIN' => 1000], 500);
		$this->movements->method('receivedSpendByCategorySince')->willReturn(['CLIN' => 900]); // remaining 100
		$this->sheets->method('findById')->willReturn($this->sheet());
		$this->lines->method('findBySheet')->willReturn([$this->line(1, 4)]); // est 400 → overage 300 ≤ 500
		$this->items->method('findById')->willReturn($this->item(1, 'CLIN', 100));
		$this->access->method('isAppAdmin')->willReturn(false);
		$this->expectException(ApprovalRequiredException::class);
		$this->svc->send('u1', 7);
	}

	public function testUnpricedInCappedCategoryNeedsAdmin(): void
	{
		$this->restockConfig(['CLIN' => 1000], 0);
		$this->movements->method('receivedSpendByCategorySince')->willReturn([]);
		$this->sheets->method('findById')->willReturn($this->sheet());
		$this->lines->method('findBySheet')->willReturn([$this->line(1, 50)]);
		$this->items->method('findById')->willReturn($this->item(1, 'CLIN', null)); // unpriced → can't prove fit
		$this->access->method('isAppAdmin')->willReturn(false);
		$this->expectException(ApprovalRequiredException::class);
		$this->svc->send('u1', 7);
	}

	public function testNoBudgetsConfiguredIsFreePass(): void
	{
		$this->restockConfig([], null);
		$this->sheets->method('findById')->willReturn($this->sheet());
		$this->lines->method('findBySheet')->willReturn([$this->line(1, 999999)]);
		$this->items->method('findById')->willReturn($this->item(1, 'CLIN', 100));
		$this->access->method('isAppAdmin')->willReturn(false);
		$this->movements->expects($this->never())->method('receivedSpendByCategorySince');
		$this->sheets->expects($this->once())->method('setStatus');
		$this->svc->send('u1', 7);
	}

	public function testReplaceLinesRetotalsDraft(): void
	{
		$this->sheets->method('findById')->willReturn($this->sheet());
		$this->items->method('findById')->willReturn($this->item(1, 'CLIN', 100));
		$this->lines->method('findBySheet')->willReturn([$this->line(1, 5)]);
		$this->lines->expects($this->once())->method('deleteBySheet')->with(7);
		$this->lines->expects($this->once())->method('insert');
		$this->sheets->expects($this->once())->method('update')
			->with($this->callback(static fn (OrderSheet $s) => $s->getTotalMinor() === 500));
		$out = $this->svc->replaceLines('u1', 7, [['itemId' => 1, 'qty' => 5]]);
		self::assertSame('draft', $out['sheet']['status']);
		self::assertCount(1, $out['lines']);
	}

	public function testReplaceLinesRejectsNonDraft(): void
	{
		$sent = $this->sheet();
		$sent->setStatus(OrderSheet::STATUS_SENT);
		$this->sheets->method('findById')->willReturn($sent);
		$this->lines->expects($this->never())->method('deleteBySheet');
		$this->expectException(ValidationException::class);
		$this->svc->replaceLines('u1', 7, [['itemId' => 1, 'qty' => 5]]);
	}

	public function testReplaceLinesRejectsEmptyEffective(): void
	{
		$this->sheets->method('findById')->willReturn($this->sheet());
		$this->lines->expects($this->once())->method('deleteBySheet'); // rolled back with the throw
		$this->expectException(ValidationException::class);
		$this->svc->replaceLines('u1', 7, [['itemId' => 0, 'qty' => 5]]);
	}

	public function testReplaceLinesRejectsBadQty(): void
	{
		$this->sheets->method('findById')->willReturn($this->sheet());
		$this->items->method('findById')->willReturn($this->item(1, 'CLIN', 100));
		$this->expectException(ValidationException::class);
		$this->svc->replaceLines('u1', 7, [['itemId' => 1, 'qty' => 0]]);
	}
}
