<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Db\Item;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\MovementMapper;
use OCA\InventoryCheck\Db\OrderSheet;
use OCA\InventoryCheck\Db\OrderSheetLine;
use OCA\InventoryCheck\Db\OrderSheetLineMapper;
use OCA\InventoryCheck\Db\OrderSheetMapper;
use OCA\InventoryCheck\Exception\PermissionDeniedException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\MovementService;
use OCA\InventoryCheck\Service\QtyScale;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Server;
use Test\TestCase;

/**
 * IV-RECEIVE-AGAINST-ORDER (C-8) end-to-end: a mobile receive posts one
 * 'receive' movement stamped ref_type='order_receive' + ref_id=sheetId,
 * attributes qty to the sheet's open lines in the same txn, keeps partial
 * sheets 'sent', closes full ones — and a sheet cancelled mid-flow rolls
 * the whole booking back (AC-7/AC-11 order_sheet_closed).
 *
 * @group DB
 */
final class ReceiveAgainstOrderIntegrationTest extends TestCase
{
	private string $uid = 'admin';
	private ItemMapper $itemMapper;
	private OrderSheetMapper $sheets;
	private OrderSheetLineMapper $lines;
	private MovementMapper $movementMapper;
	private MovementService $movements;
	private LocationService $locations;
	private BalanceMapper $balances;

	protected function setUp(): void
	{
		parent::setUp();
		$c = (new Application())->getContainer();
		$this->itemMapper = $c->get(ItemMapper::class);
		$this->sheets = $c->get(OrderSheetMapper::class);
		$this->lines = $c->get(OrderSheetLineMapper::class);
		$this->movementMapper = $c->get(MovementMapper::class);
		$this->movements = $c->get(MovementService::class);
		$this->locations = $c->get(LocationService::class);
		$this->balances = $c->get(BalanceMapper::class);
		$config = Server::get(IConfig::class);
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ALLOW_NEGATIVE, '0');
		$config->setAppValue(Application::APP_ID, QtyScale::KEY, '0');
	}

	private function newItem(string $tag): Item
	{
		$item = new Item();
		$code = 'T-RAO-' . $tag . '-' . bin2hex(random_bytes(4));
		$item->setSku($code);
		$item->setScanCode($code);
		$item->setName('order receive ' . $tag);
		$item->setActive(true);
		$item->setCreatedAt(1);
		$item->setUpdatedAt(1);
		$item->setCreatedBy($this->uid);
		/** @var Item */
		return $this->itemMapper->insert($item);
	}

	private function newLocation(string $tag): int
	{
		$loc = $this->locations->create($this->uid, [
			'code' => 'RAO-' . $tag, 'name' => 'rao ' . $tag, 'kind' => 'site',
		]);
		return (int)$loc['id'];
	}

	/** @return array{0: OrderSheet, 1: OrderSheetLine} */
	private function newSheet(int $itemId, int $qty, string $status): array
	{
		$sheet = new OrderSheet();
		$sheet->setSupplierName('T-RAO supplier');
		$sheet->setStatus($status);
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
		return [$sheet, $line];
	}

	private function qtyAt(int $itemId, int $locationId): int
	{
		$pair = $this->balances->findPair($itemId, $locationId);
		return $pair === null ? 0 : $pair->getQty();
	}

	public function testReceiveProgressThenClose(): void
	{
		$item = $this->newItem('prog');
		$locId = $this->newLocation('prog');
		[$sheet, $line] = $this->newSheet((int)$item->getId(), 5, OrderSheet::STATUS_SENT);
		$sheetId = (int)$sheet->getId();

		$r1 = $this->movements->receiveAgainstOrder(
			$this->uid, $sheetId, (int)$item->getId(), $locId, 3, null,
		);
		$m1 = $r1['movements'][0];
		$this->assertSame('receive', $m1['kind']);
		$this->assertSame(MovementService::REF_TYPE_ORDER_RECEIVE, $m1['refType']);
		$this->assertSame($sheetId, $m1['refId']);
		$this->assertSame(3, $this->qtyAt((int)$item->getId(), $locId));
		$this->assertSame(3, $this->receivedQty($sheetId, (int)$line->getId()));
		// Partial: sheet stays 'sent' and re-openable for the next receive.
		$this->assertSame(OrderSheet::STATUS_SENT, $this->sheets->findById($sheetId)->getStatus());

		$this->movements->receiveAgainstOrder(
			$this->uid, $sheetId, (int)$item->getId(), $locId, 2, null,
		);
		$this->assertSame(5, $this->qtyAt((int)$item->getId(), $locId));
		$this->assertSame(5, $this->receivedQty($sheetId, (int)$line->getId()));
		$this->assertSame(OrderSheet::STATUS_RECEIVED, $this->sheets->findById($sheetId)->getStatus());

		// Both receive legs carry the sheet ref — the audit join is complete.
		$legs = array_values(array_filter(
			$this->movementRowsForItem((int)$item->getId()),
			static fn (array $r): bool => $r['ref_type'] === MovementService::REF_TYPE_ORDER_RECEIVE
				&& (int)$r['ref_id'] === $sheetId,
		));
		$this->assertCount(2, $legs);
	}

	public function testReceiveOnCancelledSheetRollsBackBooking(): void
	{
		$item = $this->newItem('cancel');
		$locId = $this->newLocation('cancel');
		[$sheet] = $this->newSheet((int)$item->getId(), 4, OrderSheet::STATUS_SENT);
		$sheetId = (int)$sheet->getId();
		$this->sheets->setStatus($sheetId, OrderSheet::STATUS_CANCELLED, time());

		try {
			$this->movements->receiveAgainstOrder(
				$this->uid, $sheetId, (int)$item->getId(), $locId, 2, null,
			);
			$this->fail('receive against a cancelled sheet must refuse');
		} catch (ValidationException $e) {
			$this->assertSame('order_sheet_closed', $e->getMessage());
		}
		// Whole booking rolled back: no balance, no movement leg.
		$this->assertSame(0, $this->qtyAt((int)$item->getId(), $locId));
		$this->assertSame([], $this->movementRowsForItem((int)$item->getId()));
	}

	public function testReceiveAgainstOrderRequiresOffice(): void
	{
		$users = Server::get(IUserManager::class);
		$uid = 'iv-rao-field' . bin2hex(random_bytes(3));
		if ($users->get($uid) === null) {
			$users->createUser($uid, bin2hex(random_bytes(8)));
		}
		$item = $this->newItem('gate');
		$locId = $this->newLocation('gate');
		[$sheet] = $this->newSheet((int)$item->getId(), 2, OrderSheet::STATUS_SENT);

		$this->expectException(PermissionDeniedException::class);
		$this->movements->receiveAgainstOrder(
			$uid, (int)$sheet->getId(), (int)$item->getId(), $locId, 1, null,
		);
	}

	private function receivedQty(int $sheetId, int $lineId): int
	{
		foreach ($this->lines->findBySheet($sheetId) as $line) {
			if ((int)$line->getId() === $lineId) {
				return $line->getReceivedQty();
			}
		}
		$this->fail("line $lineId not found on sheet $sheetId");
	}

	/** @return list<array<string, mixed>> raw movement rows for the item */
	private function movementRowsForItem(int $itemId): array
	{
		$qb = Server::get(\OCP\IDBConnection::class)->getQueryBuilder();
		$qb->select('kind', 'qty_delta', 'ref_type', 'ref_id')
			->from(MovementMapper::TABLE)
			->where($qb->expr()->eq('item_id', $qb->createNamedParameter($itemId, \PDO::PARAM_INT)));
		return $qb->executeQuery()->fetchAll();
	}
}
