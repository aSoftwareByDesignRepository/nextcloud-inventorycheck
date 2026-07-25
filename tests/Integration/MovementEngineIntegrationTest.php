<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\LocationMapper;
use OCA\InventoryCheck\Db\MovementMapper;
use OCA\InventoryCheck\Exception\InsufficientStockException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\MovementService;
use OCP\IConfig;
use OCP\Server;
use Test\TestCase;

/**
 * Movement engine happy path + conflict + ledger invariant (AC-4…AC-7).
 *
 * @group DB
 */
final class MovementEngineIntegrationTest extends TestCase
{
	private MovementService $movements;
	private LocationService $locations;
	private ItemService $items;
	private BalanceMapper $balances;
	private MovementMapper $movementMapper;
	private string $uid = 'admin';

	protected function setUp(): void
	{
		parent::setUp();
		$app = new Application();
		$c = $app->getContainer();
		$this->movements = $c->get(MovementService::class);
		$this->locations = $c->get(LocationService::class);
		$this->items = $c->get(ItemService::class);
		$this->balances = $c->get(BalanceMapper::class);
		$this->movementMapper = $c->get(MovementMapper::class);

		// Ensure office powers for admin via system admin path (L0).
		$config = Server::get(IConfig::class);
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ALLOW_NEGATIVE, '0');
	}

	public function testReceiveTransferIssueAndLedgerInvariant(): void
	{
		$suffix = bin2hex(random_bytes(4));
		$wh = $this->locations->create($this->uid, [
			'code' => 'T-WH-' . $suffix,
			'name' => 'Test WH',
			'kind' => 'warehouse',
		]);
		$van = $this->locations->create($this->uid, [
			'code' => 'T-VAN-' . $suffix,
			'name' => 'Test Van',
			'kind' => 'van',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => 'T-SKU-' . $suffix,
			'name' => 'Test filter',
			'reorderLevel' => 5,
		]);
		$itemId = (int)$item['id'];
		$whId = (int)$wh['id'];
		$vanId = (int)$van['id'];

		$recv = $this->movements->receive($this->uid, $itemId, $whId, 10, 'seed');
		$this->assertSame(10, $recv['balances'][0]['qty']);
		$this->assertSame(10, $recv['movements'][0]['qtyAfter']);

		$xfer = $this->movements->transfer($this->uid, $itemId, $whId, $vanId, 3, 'to van');
		$this->assertCount(2, $xfer['movements']);
		$this->assertSame($xfer['movements'][0]['transferGroup'], $xfer['movements'][1]['transferGroup']);
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
			(string)$xfer['movements'][0]['transferGroup'],
		);
		$out = $xfer['movements'][0]['kind'] === 'transfer_out' ? $xfer['movements'][0] : $xfer['movements'][1];
		$in = $xfer['movements'][0]['kind'] === 'transfer_in' ? $xfer['movements'][0] : $xfer['movements'][1];
		$this->assertSame($whId, (int)$out['locationId']);
		$this->assertSame($vanId, (int)$out['counterpartyLocId']);
		$this->assertSame($vanId, (int)$in['locationId']);
		$this->assertSame($whId, (int)$in['counterpartyLocId']);

		$whBal = $this->balances->findPair($itemId, $whId);
		$vanBal = $this->balances->findPair($itemId, $vanId);
		$this->assertSame(7, $whBal?->getQty());
		$this->assertSame(3, $vanBal?->getQty());

		$this->expectException(InsufficientStockException::class);
		try {
			$this->movements->issue($this->uid, $itemId, $whId, 8, 'too much');
		} finally {
			// Balance unchanged after failed issue
			$this->assertSame(7, $this->balances->findPair($itemId, $whId)?->getQty());
			$this->assertLedgerMatchesBalances($itemId);
		}
	}

	public function testSameLocationTransferRejected(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$loc = $this->locations->create($this->uid, [
			'code' => 'T-L-' . $suffix,
			'name' => 'Loc',
			'kind' => 'other',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => 'T-I-' . $suffix,
			'name' => 'Item',
		]);
		$this->expectException(ValidationException::class);
		$this->movements->transfer(
			$this->uid,
			(int)$item['id'],
			(int)$loc['id'],
			(int)$loc['id'],
			1,
			null,
		);
	}

	public function testAdjustSetNoopRejected(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$loc = $this->locations->create($this->uid, [
			'code' => 'T-A-' . $suffix,
			'name' => 'Loc',
			'kind' => 'other',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => 'T-AI-' . $suffix,
			'name' => 'Item',
		]);
		$itemId = (int)$item['id'];
		$locId = (int)$loc['id'];
		$this->movements->receive($this->uid, $itemId, $locId, 5, null);
		$this->expectException(ValidationException::class);
		$this->movements->adjust($this->uid, $itemId, $locId, 'set', 5, null, null);
	}

	private function assertLedgerMatchesBalances(int $itemId): void
	{
		$sums = $this->movementMapper->sumDeltasByPair();
		$bals = $this->balances->search($itemId, null, false, 200, 0);
		foreach ($bals['data'] as $bal) {
			$key = $bal->getItemId() . ':' . $bal->getLocationId();
			$expected = $sums[$key] ?? 0;
			$this->assertSame($expected, $bal->getQty(), "ledger drift at $key");
		}
	}
}
