<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Db\Item;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\OrderSheet;
use OCA\InventoryCheck\Db\OrderSheetLine;
use OCA\InventoryCheck\Db\OrderSheetLineMapper;
use OCA\InventoryCheck\Db\OrderSheetMapper;
use OCA\InventoryCheck\Db\SupplierMapper;
use OCA\InventoryCheck\Exception\PermissionDeniedException;
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
 * IV-RECEIVE-AGAINST-ORDER (C-8): mobile order-sheet mirror.
 *
 * Covers the NN-01 qty-only payload census, the scoped applyReceiveToSheet
 * line math (partial / exact / over / cancelled / off-sheet fallback) and
 * the NN-04 route census (receive-only verbs under /mobile/v1/order-sheets).
 */
final class OrderSheetMobileTest extends TestCase
{
	private IDBConnection&MockObject $db;
	private OrderSheetMapper&MockObject $sheets;
	private OrderSheetLineMapper&MockObject $lines;
	private ItemMapper&MockObject $items;
	private AccessControlService&MockObject $access;
	private OrderSheetService $svc;

	protected function setUp(): void
	{
		$this->db = $this->createMock(IDBConnection::class);
		$this->sheets = $this->createMock(OrderSheetMapper::class);
		$this->lines = $this->createMock(OrderSheetLineMapper::class);
		$this->items = $this->createMock(ItemMapper::class);
		$this->access = $this->createMock(AccessControlService::class);
		$config = $this->createMock(IConfig::class);
		$clock = $this->createMock(Clock::class);
		$clock->method('now')->willReturn(1700000000);
		$this->svc = new OrderSheetService(
			$this->db,
			$this->sheets,
			$this->lines,
			$this->items,
			$this->createMock(SupplierMapper::class),
			$this->access,
			new RestockConfig($config),
			$clock,
			$config,
		);
	}

	private function sheet(string $status = OrderSheet::STATUS_SENT): OrderSheet
	{
		$s = new OrderSheet();
		$s->setId(12);
		$s->setSupplierName('Bären Baumarkt');
		$s->setSupplierId(7);
		$s->setStatus($status);
		$s->setTotalMinor(123456);
		$s->setNote('confidential note');
		$s->setCreatedBy('office1');
		$s->setCreatedAt(1699000000);
		$s->setSentAt(1699500000);
		return $s;
	}

	private function line(int $id, int $itemId, int $qty, int $received): OrderSheetLine
	{
		$l = new OrderSheetLine();
		$l->setId($id);
		$l->setSheetId(12);
		$l->setItemId($itemId);
		$l->setQty($qty);
		$l->setReceivedQty($received);
		$l->setLineEstMinor(4200);
		return $l;
	}

	private function item(int $id, string $trackMode = 'none'): Item
	{
		$i = new Item();
		$i->setId($id);
		$i->setSku('SKU-' . $id);
		$i->setScanCode('SCAN-' . $id);
		$i->setName('Item ' . $id);
		$i->setUom('pcs');
		$i->setTrackMode($trackMode);
		return $i;
	}

	/** NN-01: the mobile list payload must contain qty-only keys. */
	public function testListForMobileCensusStripsMoneyFields(): void
	{
		$this->access->expects($this->once())->method('requireOffice')->with('anna');
		$this->sheets->method('search')->with('sent', 20, 0)->willReturn([$this->sheet()]);
		$this->lines->method('lineCountsForSheets')->with([12])->willReturn([12 => 3]);

		$out = $this->svc->listForMobile('anna', 'sent', 20, 0);
		$this->assertCount(1, $out['data']);
		$row = $out['data'][0];
		$this->assertSame(12, $row['id']);
		$this->assertSame('Bären Baumarkt', $row['supplierName']);
		$this->assertSame('sent', $row['status']);
		$this->assertSame(3, $row['lineCount']);
		foreach (array_keys($row) as $key) {
			$this->assertDoesNotMatchRegularExpression(
				'/minor|price|cost|est|supplierId|supplier_id|total|note/i',
				$key,
				'NN-01: money/supplier-term key leaked to the mobile payload',
			);
		}
	}

	/** NN-01 detail: per-line qty fields only — lineEstMinor never leaves. */
	public function testShowForMobileCensusStripsMoneyFields(): void
	{
		$this->access->expects($this->once())->method('requireOffice')->with('anna');
		$this->sheets->method('findById')->with(12)->willReturn($this->sheet());
		$this->lines->method('findBySheet')->with(12)->willReturn([
			$this->line(1, 5, 10, 8),
			$this->line(2, 6, 4, 4),
		]);
		$this->items->method('findById')->willReturnMap([
			[5, $this->item(5)],
			[6, $this->item(6, 'lot')],
		]);

		$out = $this->svc->showForMobile('anna', 12);
		foreach (array_keys($out) as $key) {
			$this->assertDoesNotMatchRegularExpression(
				'/minor|price|cost|est|supplierId|supplier_id|total|note/i',
				$key,
			);
		}
		$this->assertCount(2, $out['lines']);
		$l0 = $out['lines'][0];
		$this->assertSame(10, $l0['qtyOrdered']);
		$this->assertSame(8, $l0['qtyReceived']);
		$this->assertSame(2, $l0['qtyOpen']);
		$this->assertFalse($l0['lotRequired']);
		$this->assertSame('SCAN-5', $l0['scanCode']);
		$l1 = $out['lines'][1];
		$this->assertSame(0, $l1['qtyOpen']);
		$this->assertTrue($l1['lotRequired']);
		foreach ($out['lines'] as $line) {
			foreach (array_keys($line) as $key) {
				$this->assertDoesNotMatchRegularExpression(
					'/minor|price|cost|est|supplier/i',
					$key,
					'NN-01: line key leaked money/supplier data',
				);
			}
		}
	}

	public function testMobileSurfacesRequireOffice(): void
	{
		$this->access->method('requireOffice')
			->willThrowException(new PermissionDeniedException());
		$this->expectException(PermissionDeniedException::class);
		$this->svc->listForMobile('field1', 'sent', 20, 0);
	}

	public function testMobileShowRequiresOffice(): void
	{
		$this->access->method('requireOffice')
			->willThrowException(new PermissionDeniedException());
		$this->expectException(PermissionDeniedException::class);
		$this->svc->showForMobile('field1', 12);
	}

	/** Exact + partial fill: only open qty is covered, sheet stays open when lines remain. */
	public function testApplyReceiveToSheetPartialCover(): void
	{
		$this->sheets->method('findById')->with(12)->willReturn($this->sheet());
		$this->lines->method('linesForItem')->with(5, 12)->willReturn([
			['id' => 1, 'item_id' => 5, 'qty' => 10, 'received_qty' => 8],
		]);
		$this->lines->expects($this->once())
			->method('incrementReceivedQty')
			->with(1, 2);
		$this->lines->method('allLinesReceived')->with(12)->willReturn(false);
		$this->sheets->expects($this->never())->method('setStatus');

		$this->svc->applyReceiveToSheet(12, 5, 2);
	}

	/** Full cover → sheet auto-closes received (AC-3). */
	public function testApplyReceiveToSheetExactClose(): void
	{
		$this->sheets->method('findById')->with(12)->willReturn($this->sheet());
		$this->lines->method('linesForItem')->with(5, 12)->willReturn([
			['id' => 1, 'item_id' => 5, 'qty' => 10, 'received_qty' => 8],
		]);
		$this->lines->expects($this->once())
			->method('incrementReceivedQty')
			->with(1, 2);
		$this->lines->method('allLinesReceived')->with(12)->willReturn(true);
		$this->sheets->expects($this->once())
			->method('setStatus')
			->with(12, OrderSheet::STATUS_RECEIVED, 1700000000);

		$this->svc->applyReceiveToSheet(12, 5, 2);
	}

	/**
	 * Over-receive: 5 against open 2 covers 2 then lands the 3 surplus on the
	 * same line UNCAPPED — the diff screen needs received > ordered (§9).
	 */
	public function testApplyReceiveToSheetOverReceiveIsUncapped(): void
	{
		$this->sheets->method('findById')->with(12)->willReturn($this->sheet());
		$this->lines->method('linesForItem')->with(5, 12)->willReturn([
			['id' => 1, 'item_id' => 5, 'qty' => 10, 'received_qty' => 8],
		]);
		$calls = [];
		$this->lines->expects($this->exactly(2))
			->method('incrementReceivedQty')
			->willReturnCallback(function (int $lineId, int $qty) use (&$calls): void {
				$calls[] = [$lineId, $qty];
			});
		$this->lines->method('allLinesReceived')->willReturn(true);
		$this->sheets->expects($this->once())->method('setStatus');

		$this->svc->applyReceiveToSheet(12, 5, 5);
		$this->assertSame([[1, 2], [1, 3]], $calls);
	}

	/** Receiving more against an already-filled line still records over-delivery. */
	public function testApplyReceiveToSheetOverOnFilledLine(): void
	{
		$this->sheets->method('findById')->with(12)->willReturn($this->sheet());
		$this->lines->method('linesForItem')->with(5, 12)->willReturn([
			['id' => 1, 'item_id' => 5, 'qty' => 10, 'received_qty' => 10],
		]);
		$this->lines->expects($this->once())
			->method('incrementReceivedQty')
			->with(1, 3);
		$this->lines->method('allLinesReceived')->willReturn(true);

		$this->svc->applyReceiveToSheet(12, 5, 3);
	}

	/** AC-7/AC-11: cancelled sheet mid-flow rejects the whole receive. */
	public function testApplyReceiveToSheetRejectsCancelled(): void
	{
		$this->sheets->method('findById')->with(12)
			->willReturn($this->sheet(OrderSheet::STATUS_CANCELLED));
		$this->lines->expects($this->never())->method('incrementReceivedQty');
		$this->lines->expects($this->never())->method('linesForItem');
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('order_sheet_closed');
		$this->svc->applyReceiveToSheet(12, 5, 2);
	}

	/** AC-2: item not on the sheet falls back to plain-receive global coverage. */
	public function testApplyReceiveToSheetOffSheetFallsBackToGlobal(): void
	{
		$this->sheets->method('findById')->with(12)->willReturn($this->sheet());
		$this->lines->method('linesForItem')->with(9, 12)->willReturn([]);
		// Global path: sheet 20 has an open line for item 9 — covers it.
		$this->sheets->method('openSheetIdsForItem')->with(9)->willReturn([20]);
		$this->lines->method('openLinesForItem')->with(9, 20)->willReturn([
			['id' => 44, 'item_id' => 9, 'qty' => 6, 'received_qty' => 1],
		]);
		$this->lines->expects($this->once())
			->method('decrementLine')
			->with(44, 3);
		$this->lines->expects($this->never())->method('incrementReceivedQty');
		$this->lines->method('allLinesReceived')->with(20)->willReturn(false);

		$this->svc->applyReceiveToSheet(12, 9, 3);
	}

	/**
	 * NN-04 route census: under /mobile/v1/order-sheets only the list,
	 * detail and receive verbs exist — no create/edit/send/cancel on mobile.
	 */
	public function testMobileOrderSheetRouteCensusIsReceiveOnly(): void
	{
		$routes = (string)file_get_contents(dirname(__DIR__, 3) . '/appinfo/routes.php');
		preg_match_all(
			"/'name' => 'mobile#([a-zA-Z]+)', 'url' => '(\\/mobile\\/v1\\/order-sheets[^']*)', 'verb' => '([A-Z]+)'/",
			$routes,
			$m,
			PREG_SET_ORDER,
		);
		$verbs = [];
		foreach ($m as $row) {
			$verbs[] = $row[3] . ' ' . $row[2];
		}
		sort($verbs);
		$this->assertSame([
			'GET /mobile/v1/order-sheets',
			'GET /mobile/v1/order-sheets/{id}',
			'POST /mobile/v1/order-sheets/{id}/receive',
		], $verbs);
		// Belt-and-braces: no mutating sheet-admin verb anywhere on mobile.
		$this->assertDoesNotMatchRegularExpression(
			'/mobile#orderSheet(?:Send|Cancel|Create|Update|Delete|Print)/',
			$routes,
		);
	}
}
