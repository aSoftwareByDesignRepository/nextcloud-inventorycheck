<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Db\MovementMapper;
use OCA\InventoryCheck\Exception\ConflictException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\CsvImportService;
use OCA\InventoryCheck\Service\CycleCountService;
use OCA\InventoryCheck\Service\FlangeService;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\LocationFavouriteService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\MovementService;
use OCP\IConfig;
use OCP\Server;
use Test\TestCase;

/**
 * Wave A2 / B1 / B2 / B4 integration — import ledger, cycle-count, flange, favourites.
 *
 * @group DB
 */
final class WaveAbFeaturesIntegrationTest extends TestCase
{
	private string $uid = 'admin';
	private CsvImportService $import;
	private CycleCountService $counts;
	private LocationService $locations;
	private ItemService $items;
	private MovementService $movements;
	private BalanceMapper $balances;
	private MovementMapper $movementMapper;
	private LocationFavouriteService $favourites;
	private FlangeService $flange;

	protected function setUp(): void
	{
		parent::setUp();
		$app = new Application();
		$c = $app->getContainer();
		$this->import = $c->get(CsvImportService::class);
		$this->counts = $c->get(CycleCountService::class);
		$this->locations = $c->get(LocationService::class);
		$this->items = $c->get(ItemService::class);
		$this->movements = $c->get(MovementService::class);
		$this->balances = $c->get(BalanceMapper::class);
		$this->movementMapper = $c->get(MovementMapper::class);
		$this->favourites = $c->get(LocationFavouriteService::class);
		$this->flange = $c->get(FlangeService::class);
		$config = Server::get(IConfig::class);
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ALLOW_NEGATIVE, '0');
		// Isolate from Wave C tests that may leave qty_scale=3 enabled.
		$config->setAppValue(Application::APP_ID, \OCA\InventoryCheck\Service\QtyScale::KEY, '0');
	}

	/** Create returns a paginated line window; page until the target item appears. */
	private function lineIdForItem(int $campaignId, int $itemId): int
	{
		$offset = 0;
		$limit = 200;
		do {
			$page = $this->counts->get($this->uid, $campaignId, $limit, $offset);
			foreach ($page['lines'] as $line) {
				if ((int)$line['itemId'] === $itemId) {
					return (int)$line['id'];
				}
			}
			$offset += $limit;
		} while ($offset < (int)($page['linesTotal'] ?? 0));
		self::fail('inventur line for item ' . $itemId . ' not found in campaign ' . $campaignId);
	}

	public function testCsvDryRunReportsDuplicateAndCommitIsAtomicWithOpeningReceive(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$loc = $this->locations->create($this->uid, [
			'code' => 'CSV-L-' . $suffix,
			'name' => 'CSV Loc',
			'kind' => 'warehouse',
		]);
		$sku1 = 'CSV-A-' . $suffix;
		$sku2 = 'CSV-B-' . $suffix;
		$csv = "sku;name;reorder_level;opening_location_code;opening_qty\n"
			. "{$sku1};Alpha;3;{$loc['code']};7\n"
			. "{$sku2};Beta;0;;\n";

		$dry = $this->import->dryRun($this->uid, $csv);
		self::assertSame(2, $dry['ok']);
		self::assertSame([], $dry['errors']);

		$dup = $this->import->dryRun($this->uid, $csv . "{$sku1};Dup;0;;\n");
		self::assertSame(2, $dup['ok']);
		self::assertNotEmpty($dup['errors']);
		self::assertSame('code_exists', $dup['errors'][0]['code']);

		$commit = $this->import->commit($this->uid, $csv, false);
		self::assertSame(2, $commit['created']);
		self::assertSame(1, $commit['received']);

		$item = $this->items->byCode($this->uid, $sku1);
		$bal = $this->balances->findPair((int)$item['id'], (int)$loc['id']);
		self::assertNotNull($bal);
		self::assertSame(7, $bal->getQty());

		$refuse = $this->import->commit($this->uid, $csv, false);
		self::assertSame(0, $refuse['created']);
		self::assertNotEmpty($refuse['errors']);
	}

	public function testCycleCountPostsAdjustOnCloseAndBlocksIncomplete(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$loc = $this->locations->create($this->uid, [
			'code' => 'CC-L-' . $suffix,
			'name' => 'Count Loc',
			'kind' => 'warehouse',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => 'CC-I-' . $suffix,
			'name' => 'Counted',
			'uom' => 'pcs',
			'reorderLevel' => 0,
		]);
		$this->movements->receive($this->uid, (int)$item['id'], (int)$loc['id'], 10, 'seed');

		$camp = $this->counts->create($this->uid, (int)$loc['id'], 'Inventur ' . $suffix);
		self::assertSame(CycleCountService::STATUS_OPEN, $camp['status']);
		self::assertNotEmpty($camp['lines']);

		$this->counts->startCounting($this->uid, (int)$camp['id']);
		$lineId = $this->lineIdForItem((int)$camp['id'], (int)$item['id']);

		try {
			$this->counts->close($this->uid, (int)$camp['id'], false);
			self::fail('expected count_incomplete');
		} catch (ValidationException $e) {
			self::assertSame('count_incomplete', $e->getErrorCode());
		}

		$this->counts->setCount($this->uid, $lineId, 4);
		$closed = $this->counts->close($this->uid, (int)$camp['id'], true);
		self::assertSame(CycleCountService::STATUS_CLOSED, $closed['status']);

		$bal = $this->balances->findPair((int)$item['id'], (int)$loc['id']);
		self::assertSame(4, $bal->getQty());

		$movs = $this->movementMapper->search('adjust', (int)$item['id'], (int)$loc['id'], null, null, null, 10, 0);
		self::assertGreaterThanOrEqual(1, $movs['total']);
	}

	public function testCycleCountCloseSkipsMatchingCounts(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$loc = $this->locations->create($this->uid, [
			'code' => 'CC-ML-' . $suffix,
			'name' => 'Match Loc',
			'kind' => 'warehouse',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => 'CC-MI-' . $suffix,
			'name' => 'Match Item',
			'uom' => 'pcs',
			'reorderLevel' => 0,
		]);
		$this->movements->receive($this->uid, (int)$item['id'], (int)$loc['id'], 7, 'seed');

		$camp = $this->counts->create($this->uid, (int)$loc['id'], 'Match ' . $suffix);
		$this->counts->startCounting($this->uid, (int)$camp['id']);
		$lineId = $this->lineIdForItem((int)$camp['id'], (int)$item['id']);
		$this->counts->setCount($this->uid, $lineId, 7);
		$closed = $this->counts->close($this->uid, (int)$camp['id'], true);
		self::assertSame(CycleCountService::STATUS_CLOSED, $closed['status']);
		self::assertSame([], $closed['postedMovementIds'] ?? []);
		$bal = $this->balances->findPair((int)$item['id'], (int)$loc['id']);
		self::assertSame(7, $bal->getQty());
	}

	/**
	 * UC-C2: mid-count receive must not be wiped by close unless office acknowledges.
	 */
	public function testCycleCountCloseBlocksMidCountReceiveUnlessAcknowledged(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$loc = $this->locations->create($this->uid, [
			'code' => 'CC-XL-' . $suffix,
			'name' => 'Conflict Loc',
			'kind' => 'warehouse',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => 'CC-XI-' . $suffix,
			'name' => 'Conflict Item',
			'uom' => 'pcs',
			'reorderLevel' => 0,
		]);
		$this->movements->receive($this->uid, (int)$item['id'], (int)$loc['id'], 10, 'seed');

		$camp = $this->counts->create($this->uid, (int)$loc['id'], 'Conflict ' . $suffix);
		$this->counts->startCounting($this->uid, (int)$camp['id']);
		$lineId = $this->lineIdForItem((int)$camp['id'], (int)$item['id']);

		// Mid-count receive — live qty drifts from frozen snapshot.
		$this->movements->receive($this->uid, (int)$item['id'], (int)$loc['id'], 2, 'mid-count');
		$view = $this->counts->get($this->uid, (int)$camp['id'], 200, 0);
		self::assertTrue($view['hasConflicts']);
		$conflictLine = null;
		$offset = 0;
		do {
			$page = $this->counts->get($this->uid, (int)$camp['id'], 200, $offset);
			foreach ($page['lines'] as $line) {
				if ((int)$line['id'] === $lineId) {
					$conflictLine = $line;
					break 2;
				}
			}
			$offset += 200;
		} while ($offset < (int)($page['linesTotal'] ?? 0));
		self::assertNotNull($conflictLine);
		self::assertTrue($conflictLine['conflict']);
		self::assertSame(12, (int)$conflictLine['currentQty']);
		self::assertSame(10, (int)$conflictLine['systemQty']);

		$this->counts->setCount($this->uid, $lineId, 10);
		try {
			$this->counts->close($this->uid, (int)$camp['id'], true, false);
			self::fail('expected count_conflict');
		} catch (ValidationException $e) {
			self::assertSame('count_conflict', $e->getErrorCode());
		}
		$bal = $this->balances->findPair((int)$item['id'], (int)$loc['id']);
		self::assertSame(12, $bal->getQty(), 'blocked close must not wipe mid-count receive');

		$closed = $this->counts->close($this->uid, (int)$camp['id'], true, true);
		self::assertSame(CycleCountService::STATUS_CLOSED, $closed['status']);
		$bal = $this->balances->findPair((int)$item['id'], (int)$loc['id']);
		self::assertSame(10, $bal->getQty(), 'acknowledged close may apply counted qty');
	}

	public function testCycleCountCloseFailsWhenTrackModeFlipsMidCampaign(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$loc = $this->locations->create($this->uid, [
			'code' => 'CC-TL-' . $suffix,
			'name' => 'Track Flip Loc',
			'kind' => 'warehouse',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => 'CC-TI-' . $suffix,
			'name' => 'Track Flip Item',
			'uom' => 'pcs',
			'reorderLevel' => 0,
			'trackMode' => 'none',
		]);
		$this->movements->receive($this->uid, (int)$item['id'], (int)$loc['id'], 5, 'seed');

		$camp = $this->counts->create($this->uid, (int)$loc['id'], 'TrackFlip ' . $suffix);
		$this->counts->startCounting($this->uid, (int)$camp['id']);
		$lineId = $this->lineIdForItem((int)$camp['id'], (int)$item['id']);
		$this->counts->setCount($this->uid, $lineId, 4);

		// Zero stock first — upgrading trackMode with anonymous qty is refused.
		$this->movements->adjust($this->uid, (int)$item['id'], (int)$loc['id'], 'set', 0, null, 'clear for trackMode', null, true, 'correction');

		// Mid-campaign flip to lot — close must not brick with invalid_lot_code.
		$this->items->update($this->uid, (int)$item['id'], ['trackMode' => 'lot']);

		try {
			$this->counts->close($this->uid, (int)$camp['id'], true, false);
			self::fail('expected track_mode_changed');
		} catch (ValidationException $e) {
			self::assertSame('track_mode_changed', $e->getErrorCode());
		}
		$view = $this->counts->get($this->uid, (int)$camp['id']);
		self::assertSame(CycleCountService::STATUS_COUNTING, $view['status']);
	}

	public function testTrackModeUpgradeRefusedWhileStockRemains(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$loc = $this->locations->create($this->uid, [
			'code' => 'TM-L-' . $suffix,
			'name' => 'Track Mode Loc',
			'kind' => 'warehouse',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => 'TM-I-' . $suffix,
			'name' => 'Track Mode Item',
			'uom' => 'pcs',
			'reorderLevel' => 0,
			'trackMode' => 'none',
		]);
		$this->movements->receive($this->uid, (int)$item['id'], (int)$loc['id'], 3, 'seed');

		try {
			$this->items->update($this->uid, (int)$item['id'], ['trackMode' => 'serial']);
			self::fail('expected track_mode_requires_zero_stock');
		} catch (ConflictException $e) {
			self::assertSame('track_mode_requires_zero_stock', $e->getErrorCode());
		}

		$this->movements->adjust($this->uid, (int)$item['id'], (int)$loc['id'], 'set', 0, null, 'clear', null, true, 'correction');
		$updated = $this->items->update($this->uid, (int)$item['id'], ['trackMode' => 'serial']);
		self::assertSame('serial', $updated['trackMode']);
	}

	public function testCannotDeleteOrDeactivateItemOnOpenStocktake(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$loc = $this->locations->create($this->uid, [
			'code' => 'CC-DL-' . $suffix,
			'name' => 'Delete Guard Loc',
			'kind' => 'warehouse',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => 'CC-DI-' . $suffix,
			'name' => 'Delete Guard Item',
			'uom' => 'pcs',
			'reorderLevel' => 0,
		]);
		// No movements — delete would otherwise succeed and brick inventur close.
		$camp = $this->counts->create($this->uid, (int)$loc['id'], 'DeleteGuard ' . $suffix);
		self::assertSame(CycleCountService::STATUS_OPEN, $camp['status']);

		try {
			$this->items->delete($this->uid, (int)$item['id']);
			self::fail('expected item_in_open_stocktake');
		} catch (ConflictException $e) {
			self::assertSame('item_in_open_stocktake', $e->getErrorCode());
		}

		try {
			$this->items->update($this->uid, (int)$item['id'], ['active' => false]);
			self::fail('expected item_in_open_stocktake on deactivate');
		} catch (ConflictException $e) {
			self::assertSame('item_in_open_stocktake', $e->getErrorCode());
		}

		try {
			$this->locations->delete($this->uid, (int)$loc['id']);
			self::fail('expected location_in_open_stocktake');
		} catch (ConflictException $e) {
			self::assertSame('location_in_open_stocktake', $e->getErrorCode());
		}

		try {
			$this->locations->update($this->uid, (int)$loc['id'], ['active' => false]);
			self::fail('expected location_in_open_stocktake on deactivate');
		} catch (ConflictException $e) {
			self::assertSame('location_in_open_stocktake', $e->getErrorCode());
		}
	}

	public function testFavouriteCapAndIdempotentAdd(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$loc = $this->locations->create($this->uid, [
			'code' => 'FV-L-' . $suffix,
			'name' => 'Fav',
			'kind' => 'van',
		]);
		$list = $this->favourites->add($this->uid, (int)$loc['id']);
		self::assertNotEmpty($list);
		$again = $this->favourites->add($this->uid, (int)$loc['id']);
		self::assertCount(count($list), $again);
		$removed = $this->favourites->remove($this->uid, (int)$loc['id']);
		foreach ($removed as $row) {
			self::assertNotSame((int)$loc['id'], (int)$row['id']);
		}
	}

	public function testFlangeIssueWithRefWritesImmutableRefAndInsufficientIsSoft(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$loc = $this->locations->create($this->uid, [
			'code' => 'FL-L-' . $suffix,
			'name' => 'Flange Loc',
			'kind' => 'van',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => 'FL-I-' . $suffix,
			'name' => 'Flange item',
			'uom' => 'pcs',
			'reorderLevel' => 0,
		]);
		$this->movements->receive($this->uid, (int)$item['id'], (int)$loc['id'], 2, 'seed');

		$config = Server::get(IConfig::class);
		$config->setAppValue(Application::APP_ID, FlangeService::KEY_MAINT_ENABLED, '1');
		$this->flange->setDefaultLocationId((int)$loc['id']);

		$result = $this->flange->issueForMaintWo($this->uid, 9001, [
			['sku' => $item['sku'], 'qty' => 1],
		], (int)$loc['id']);
		// Peer may be absent → soft fail path still ledger-safe when enabled+location set.
		if ($result['inventory_sync'] === 'peer_absent') {
			// Force direct issueWithRef path for ledger proof.
			$direct = $this->movements->issueWithRef(
				$this->uid,
				(int)$item['id'],
				(int)$loc['id'],
				1,
				'test',
				FlangeService::REF_MAINT_WO,
				9001,
			);
			self::assertSame('maint_wo', $direct['movements'][0]['refType']);
			self::assertSame(9001, $direct['movements'][0]['refId']);
		} else {
			self::assertContains($result['inventory_sync'], ['ok', 'failed']);
		}

		$fail = $this->flange->issueForMaintWo($this->uid, 9002, [
			['sku' => $item['sku'], 'qty' => 999],
		], (int)$loc['id']);
		if ($fail['inventory_sync'] !== 'peer_absent' && $fail['inventory_sync'] !== 'disabled') {
			self::assertSame('failed', $fail['inventory_sync']);
			self::assertSame('insufficient_stock', $fail['errors'][0]['code']);
		}
	}

	public function testCampaignStartTwiceConflicts(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$loc = $this->locations->create($this->uid, [
			'code' => 'CC2-L-' . $suffix,
			'name' => 'Count2',
			'kind' => 'shelf',
		]);
		$camp = $this->counts->create($this->uid, (int)$loc['id'], 'Twice');
		$this->counts->startCounting($this->uid, (int)$camp['id']);
		$this->expectException(ConflictException::class);
		$this->counts->startCounting($this->uid, (int)$camp['id']);
	}
}
