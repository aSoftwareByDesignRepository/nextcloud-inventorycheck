<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Db\Item;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\OrderSheet;
use OCA\InventoryCheck\Db\OrderSheetLine;
use OCA\InventoryCheck\Db\OrderSheetLineMapper;
use OCA\InventoryCheck\Db\OrderSheetMapper;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\MovementService;
use OCA\InventoryCheck\Service\QtyScale;
use OCP\IConfig;
use OCP\Server;
use Test\TestCase;

/**
 * Regression for qb_sql_fragment_bound_as_literal: QueryBuilder::set() given a
 * raw 'CASE WHEN ... END' PHP string backtick-wraps it as an identifier and the
 * UPDATE 500s on receive (2026-11-01 intake). These legs pin BOTH CASE branches
 * of decrementOnOrder + decrementLine against the real QB/DB, plus the
 * lt('received_qty','qty') column-vs-column predicate adjudicated in the same
 * fix (it is a column reference, not a literal — open lines must still match).
 *
 * @group DB
 */
final class OnOrderDecrementIntegrationTest extends TestCase
{
	private string $uid = 'admin';
	private ItemMapper $itemMapper;
	private OrderSheetMapper $sheets;
	private OrderSheetLineMapper $lines;
	private MovementService $movements;
	private LocationService $locations;

	protected function setUp(): void
	{
		parent::setUp();
		$app = new Application();
		$c = $app->getContainer();
		$this->itemMapper = $c->get(ItemMapper::class);
		$this->sheets = $c->get(OrderSheetMapper::class);
		$this->lines = $c->get(OrderSheetLineMapper::class);
		$this->movements = $c->get(MovementService::class);
		$this->locations = $c->get(LocationService::class);
		$config = Server::get(IConfig::class);
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ALLOW_NEGATIVE, '0');
		// Storage ints must equal display ints for the literal assertions below.
		$config->setAppValue(Application::APP_ID, QtyScale::KEY, '0');
	}

	private function newItem(string $tag): Item
	{
		$item = new Item();
		$code = 'T-OOD-' . $tag . '-' . bin2hex(random_bytes(4));
		$item->setSku($code);
		$item->setScanCode($code);
		$item->setName('decrement test ' . $tag);
		$item->setActive(true);
		$item->setCreatedAt(1);
		$item->setUpdatedAt(1);
		$item->setCreatedBy($this->uid);
		/** @var Item */
		return $this->itemMapper->insert($item);
	}

	/** @return array{0: int, 1: int} [sheetId, lineId] */
	private function newSentSheetWithLine(int $itemId, int $qty): array
	{
		$sheet = new OrderSheet();
		$sheet->setSupplierName('T-OOD supplier');
		$sheet->setStatus(OrderSheet::STATUS_SENT);
		$sheet->setCreatedBy($this->uid);
		$sheet->setCreatedAt(1);
		$sheet->setSentAt(1);
		$sheet = $this->sheets->insert($sheet);
		$line = new OrderSheetLine();
		$line->setSheetId((int)$sheet->getId());
		$line->setItemId($itemId);
		$line->setQty($qty);
		$line->setReceivedQty(0);
		$line = $this->lines->insert($line);
		return [(int)$sheet->getId(), (int)$line->getId()];
	}

	/** received_qty of one line, reread via the mapper's own findBySheet. */
	private function receivedQty(int $sheetId, int $lineId): int
	{
		foreach ($this->lines->findBySheet($sheetId) as $line) {
			if ((int)$line->getId() === $lineId) {
				return $line->getReceivedQty();
			}
		}
		$this->fail("line $lineId not found on sheet $sheetId");
	}

	public function testDecrementOnOrderPartialLeavesRemainder(): void
	{
		$item = $this->newItem('P');
		$id = (int)$item->getId();
		$this->itemMapper->markOnOrder($id, 5, 111, 'tester');

		// ELSE branch: 5 - 2 = 3 remainder, at/by columns survive.
		$this->itemMapper->decrementOnOrder($id, 2);

		$re = $this->itemMapper->findById($id);
		$this->assertSame(3, $re->getOnOrderQty());
		$this->assertSame(111, $re->getOnOrderAt());
		$this->assertSame('tester', $re->getOnOrderBy());
	}

	public function testDecrementOnOrderFullCoverNullsAllThree(): void
	{
		$item = $this->newItem('F');
		$id = (int)$item->getId();
		$this->itemMapper->markOnOrder($id, 5, 111, 'tester');

		// THEN branch: 7 >= 5 → all three columns NULL. On MySQL/MariaDB the
		// SET clauses evaluate left-to-right against updated values, so the
		// at/by legs must precede the on_order_qty assignment — pinned here.
		$this->itemMapper->decrementOnOrder($id, 7);

		$re = $this->itemMapper->findById($id);
		$this->assertNull($re->getOnOrderQty());
		$this->assertNull($re->getOnOrderAt());
		$this->assertNull($re->getOnOrderBy());
	}

	public function testDecrementLinePartialThenClamp(): void
	{
		$item = $this->newItem('L');
		$itemId = (int)$item->getId();
		[$sheetId, $lineId] = $this->newSentSheetWithLine($itemId, 10);

		// ELSE branch: 0 + 3 <= 10 → received_qty = 3, line still open.
		$this->lines->decrementLine($lineId, 3);
		$open = $this->lines->openLinesForItem($itemId, $sheetId);
		$this->assertCount(1, $open);
		$this->assertSame(3, $open[0]['received_qty']);
		$this->assertFalse($this->lines->allLinesReceived($sheetId));

		// THEN branch: 3 + 99 > 10 → clamps at qty, sheet fully received.
		$this->lines->decrementLine($lineId, 99);
		$this->assertTrue($this->lines->allLinesReceived($sheetId));
		$this->assertSame([], $this->lines->openLinesForItem($itemId, $sheetId));
	}

	/** lt('received_qty','qty') is a column-vs-column predicate — closed lines never match. */
	public function testOpenLinesForItemSkipsClosedLine(): void
	{
		$item = $this->newItem('LT');
		$itemId = (int)$item->getId();

		$sheet = new OrderSheet();
		$sheet->setSupplierName('T-OOD supplier');
		$sheet->setStatus(OrderSheet::STATUS_SENT);
		$sheet->setCreatedBy($this->uid);
		$sheet->setCreatedAt(1);
		$sheet->setSentAt(1);
		$sheet = $this->sheets->insert($sheet);
		$sheetId = (int)$sheet->getId();

		$closed = new OrderSheetLine();
		$closed->setSheetId($sheetId);
		$closed->setItemId($itemId);
		$closed->setQty(4);
		$closed->setReceivedQty(4); // fully covered — must not be returned
		$closed = $this->lines->insert($closed);

		$open = new OrderSheetLine();
		$open->setSheetId($sheetId);
		$open->setItemId($itemId);
		$open->setQty(8);
		$open->setReceivedQty(5); // partially covered — the only open line
		$open = $this->lines->insert($open);

		$rows = $this->lines->openLinesForItem($itemId, $sheetId);
		$this->assertCount(1, $rows);
		$this->assertSame((int)$open->getId(), $rows[0]['id']);
		$this->assertFalse($this->lines->allLinesReceived($sheetId));
	}

	/**
	 * End-to-end regression for the live 500: a receive against an on-order
	 * marker must decrement/clear it inside the movement TX, and a receive
	 * covering a sent sheet line must advance received_qty.
	 */
	public function testReceiveDecrementsMarkerAndCoversLine(): void
	{
		$loc = $this->locations->create($this->uid, [
			'code' => 'T-OOD-' . bin2hex(random_bytes(4)),
			'name' => 'decrement test loc',
			'kind' => 'warehouse',
		]);
		$item = $this->newItem('E2E');
		$itemId = (int)$item->getId();
		$this->itemMapper->markOnOrder($itemId, 5, 111, 'tester');
		[$sheetId, $lineId] = $this->newSentSheetWithLine($itemId, 10);

		$res = $this->movements->receive($this->uid, $itemId, (int)$loc['id'], 4, 'e2e');
		$this->assertSame(4, $res['balances'][0]['qty']);

		// Marker decremented to remainder 1 (at/by survive), line covered 4.
		$re = $this->itemMapper->findById($itemId);
		$this->assertSame(1, $re->getOnOrderQty());
		$this->assertSame('tester', $re->getOnOrderBy());
		$this->assertSame(4, $this->receivedQty($sheetId, $lineId));
	}
}
