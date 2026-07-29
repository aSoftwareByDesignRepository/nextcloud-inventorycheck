<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Exception\ConflictException;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\CsvExportService;
use OCA\InventoryCheck\Service\FlangeService;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\LocationAclService;
use OCA\InventoryCheck\Service\LocationFavouriteService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\LowStockService;
use OCA\InventoryCheck\Service\MovementService;
use OCA\InventoryCheck\Service\QtyScale;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Server;
use Test\TestCase;

/**
 * Wave C1–C5 integration — fractional qty, lot/serial, location ACL, project flange.
 *
 * @group DB
 */
final class WaveCFeaturesIntegrationTest extends TestCase
{
	private string $uid = 'admin';
	private LocationService $locations;
	private ItemService $items;
	private MovementService $movements;
	private BalanceMapper $balances;
	private LocationAclService $acl;
	private FlangeService $flange;
	private LocationFavouriteService $favourites;
	private LowStockService $lowStock;
	private CsvExportService $export;
	private IConfig $config;

	/** @var array<string, string> */
	private array $prevConfig = [];

	protected function setUp(): void
	{
		parent::setUp();
		$app = new Application();
		$c = $app->getContainer();
		$this->locations = $c->get(LocationService::class);
		$this->items = $c->get(ItemService::class);
		$this->movements = $c->get(MovementService::class);
		$this->balances = $c->get(BalanceMapper::class);
		$this->acl = $c->get(LocationAclService::class);
		$this->flange = $c->get(FlangeService::class);
		$this->favourites = $c->get(LocationFavouriteService::class);
		$this->lowStock = $c->get(LowStockService::class);
		$this->export = $c->get(CsvExportService::class);
		$this->config = Server::get(IConfig::class);

		foreach ([
			QtyScale::KEY,
			LocationAclService::KEY_ENABLED,
			AccessControlService::KEY_ALLOW_NEGATIVE,
			AccessControlService::KEY_OFFICE_USER_IDS,
			AccessControlService::KEY_ACCESS_RESTRICTION,
			AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS,
			FlangeService::KEY_PROJECT_ENABLED,
		] as $key) {
			$this->prevConfig[$key] = $this->config->getAppValue(Application::APP_ID, $key, '');
		}
		$this->config->setAppValue(Application::APP_ID, AccessControlService::KEY_ALLOW_NEGATIVE, '0');
		$this->config->setAppValue(Application::APP_ID, QtyScale::KEY, '0');
		$this->config->setAppValue(Application::APP_ID, LocationAclService::KEY_ENABLED, '0');
		$this->acl->replaceAll($this->uid, []);
	}

	protected function tearDown(): void
	{
		foreach ($this->prevConfig as $key => $value) {
			if ($value === '') {
				$this->config->deleteAppValue(Application::APP_ID, $key);
			} else {
				$this->config->setAppValue(Application::APP_ID, $key, $value);
			}
		}
		parent::tearDown();
	}

	public function testSerialTrackRequiresLotAndUnitQtyAndBlocksDuplicate(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$loc = $this->locations->create($this->uid, [
			'code' => 'C2-L-' . $suffix,
			'name' => 'Serial Loc',
			'kind' => 'warehouse',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => 'C2-S-' . $suffix,
			'name' => 'Serial Tool',
			'uom' => 'pcs',
			'reorderLevel' => 0,
			'trackMode' => 'serial',
		]);
		$id = (int)$item['id'];
		$locId = (int)$loc['id'];

		try {
			$this->movements->receive($this->uid, $id, $locId, 1, null, null);
			self::fail('expected invalid_lot_code');
		} catch (ValidationException $e) {
			self::assertSame('invalid_lot_code', $e->getErrorCode());
		}

		try {
			$this->movements->receive($this->uid, $id, $locId, 2, null, 'SN-1');
			self::fail('expected serial_qty_must_be_one');
		} catch (ValidationException $e) {
			self::assertSame('serial_qty_must_be_one', $e->getErrorCode());
		}

		$this->movements->receive($this->uid, $id, $locId, 1, null, 'SN-1');
		$bal = $this->balances->findPair($id, $locId);
		self::assertNotNull($bal);
		self::assertSame(1, $bal->getQty());

		try {
			$this->movements->receive($this->uid, $id, $locId, 1, null, 'SN-1');
			self::fail('expected serial_exists');
		} catch (ConflictException $e) {
			self::assertSame('serial_exists', $e->getErrorCode());
		}
	}

	public function testLocationAclHidesLocationsFromFieldUsers(): void
	{
		$users = Server::get(IUserManager::class);
		$fieldUid = 'iv-field-' . bin2hex(random_bytes(2));
		if ($users->get($fieldUid) === null) {
			$users->createUser($fieldUid, bin2hex(random_bytes(8)));
		}

		$suffix = bin2hex(random_bytes(3));
		$visible = $this->locations->create($this->uid, [
			'code' => 'ACL-V-' . $suffix,
			'name' => 'Visible',
			'kind' => 'van',
		]);
		$hidden = $this->locations->create($this->uid, [
			'code' => 'ACL-H-' . $suffix,
			'name' => 'Hidden',
			'kind' => 'site',
		]);

		$this->config->setAppValue(Application::APP_ID, LocationAclService::KEY_ENABLED, '1');
		$this->acl->setForSubject('user', $fieldUid, [(int)$visible['id']]);

		$list = $this->locations->list($fieldUid, true, 50, 0);
		$ids = array_map(static fn (array $r): int => (int)$r['id'], $list['data']);
		self::assertContains((int)$visible['id'], $ids);
		self::assertNotContains((int)$hidden['id'], $ids);

		try {
			$this->locations->get($fieldUid, (int)$hidden['id']);
			self::fail('expected unknown_location');
		} catch (NotFoundException $e) {
			self::assertSame('unknown_location', $e->getErrorCode());
		}

		$item = $this->items->create($this->uid, [
			'sku' => 'ACL-I-' . $suffix,
			'name' => 'ACL Item',
			'uom' => 'pcs',
			'reorderLevel' => 0,
		]);
		$this->movements->receive($this->uid, (int)$item['id'], (int)$hidden['id'], 3, 'seed-hidden');
		$this->movements->receive($this->uid, (int)$item['id'], (int)$visible['id'], 2, 'seed-visible');

		$movList = $this->movements->list($fieldUid, null, (int)$item['id'], null, null, null, null, 50, 0);
		foreach ($movList['data'] as $row) {
			self::assertSame((int)$visible['id'], (int)$row['locationId']);
		}
		$hiddenProbe = $this->movements->list($fieldUid, null, null, (int)$hidden['id'], null, null, null, 50, 0);
		self::assertSame([], $hiddenProbe['data']);

		try {
			$this->movements->issue($fieldUid, (int)$item['id'], (int)$hidden['id'], 1, null);
			self::fail('expected unknown_location on issue');
		} catch (NotFoundException $e) {
			self::assertSame('unknown_location', $e->getErrorCode());
		}

		// Cleanup grants so tearDown restore is clean.
		$this->acl->setForSubject('user', $fieldUid, []);
		$this->config->setAppValue(Application::APP_ID, LocationAclService::KEY_ENABLED, '0');
	}

	public function testProjectFlangeDisabledAndPeerAbsent(): void
	{
		$this->config->setAppValue(Application::APP_ID, FlangeService::KEY_PROJECT_ENABLED, '0');
		$disabled = $this->flange->issueForProject($this->uid, 1, [['sku' => 'X', 'qty' => 1]]);
		self::assertFalse($disabled['ok']);
		self::assertSame('disabled', $disabled['inventory_sync']);

		$this->config->setAppValue(Application::APP_ID, FlangeService::KEY_PROJECT_ENABLED, '1');
		$absent = $this->flange->issueForProject($this->uid, 1, [['sku' => 'X', 'qty' => 1]]);
		self::assertFalse($absent['ok']);
		self::assertContains($absent['inventory_sync'], ['peer_absent', 'failed']);
	}

	public function testSerialCapacityHonoursMilliScaleUnit(): void
	{
		// Non-destructive: temporarily pretend scale=3 without running the
		// irreversible ×1000 migration (which would poison the shared test DB).
		$this->config->setAppValue(Application::APP_ID, QtyScale::KEY, '3');
		self::assertSame(1000, QtyScale::serialUnit($this->config));

		$suffix = bin2hex(random_bytes(3));
		$loc = $this->locations->create($this->uid, [
			'code' => 'C1S-L-' . $suffix,
			'name' => 'Milli Serial Loc',
			'kind' => 'warehouse',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => 'C1S-I-' . $suffix,
			'name' => 'Milli Serial',
			'uom' => 'pcs',
			'reorderLevel' => 0,
			'trackMode' => 'serial',
		]);
		$id = (int)$item['id'];
		$locId = (int)$loc['id'];

		// Display "1" → storage 1000 under scale=3; serial must accept that unit.
		$this->movements->receive($this->uid, $id, $locId, 1000, null, 'SN-M1');
		$bal = $this->balances->findPair($id, $locId);
		self::assertNotNull($bal);
		self::assertSame(1000, $bal->getQty());

		try {
			$this->movements->receive($this->uid, $id, $locId, 1000, null, 'SN-M1');
			self::fail('expected serial_exists');
		} catch (ConflictException $e) {
			self::assertSame('serial_exists', $e->getErrorCode());
		}

		try {
			$this->movements->receive($this->uid, $id, $locId, 1, null, 'SN-M2');
			self::fail('expected serial_qty_must_be_one');
		} catch (ValidationException $e) {
			self::assertSame('serial_qty_must_be_one', $e->getErrorCode());
		}

		$this->config->setAppValue(Application::APP_ID, QtyScale::KEY, '0');
	}

	public function testItemReorderCeilingHonoursMilliMaxStorage(): void
	{
		$this->config->setAppValue(Application::APP_ID, QtyScale::KEY, '3');
		$suffix = bin2hex(random_bytes(3));
		// Display 1001 → storage 1_001_000 — must pass (bare 1e6 ceiling would reject).
		$item = $this->items->create($this->uid, [
			'sku' => 'C1R-' . $suffix,
			'name' => 'High Reorder',
			'uom' => 'pcs',
			'reorderLevel' => 1_001_000,
		]);
		self::assertSame(1_001_000, (int)$item['reorderLevel']);
		$updated = $this->items->update($this->uid, (int)$item['id'], [
			'reorderLevel' => 1_500_000,
		]);
		self::assertSame(1_500_000, (int)$updated['reorderLevel']);
		$this->config->setAppValue(Application::APP_ID, QtyScale::KEY, '0');
	}

	public function testLotTrackRequiresLotCodeAndAllowsSameLotAgain(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$loc = $this->locations->create($this->uid, [
			'code' => 'C2L-L-' . $suffix,
			'name' => 'Lot Loc',
			'kind' => 'warehouse',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => 'C2L-I-' . $suffix,
			'name' => 'Lot Cable',
			'uom' => 'm',
			'reorderLevel' => 0,
			'trackMode' => 'lot',
		]);
		$id = (int)$item['id'];
		$locId = (int)$loc['id'];

		try {
			$this->movements->receive($this->uid, $id, $locId, 5, null, null);
			self::fail('expected invalid_lot_code');
		} catch (ValidationException $e) {
			self::assertSame('invalid_lot_code', $e->getErrorCode());
		}

		$this->movements->receive($this->uid, $id, $locId, 5, null, 'LOT-A');
		$this->movements->receive($this->uid, $id, $locId, 3, null, 'LOT-A');
		$bal = $this->balances->findPair($id, $locId);
		self::assertNotNull($bal);
		self::assertSame(8, $bal->getQty());

		$this->movements->issue($this->uid, $id, $locId, 2, null, 'LOT-A');
		$bal2 = $this->balances->findPair($id, $locId);
		self::assertNotNull($bal2);
		self::assertSame(6, $bal2->getQty());
	}

	public function testByCodeAndLowStockHonourLocationAcl(): void
	{
		$users = Server::get(IUserManager::class);
		$fieldUid = 'iv-field-bc-' . bin2hex(random_bytes(2));
		if ($users->get($fieldUid) === null) {
			$users->createUser($fieldUid, bin2hex(random_bytes(8)));
		}

		$suffix = bin2hex(random_bytes(3));
		$visible = $this->locations->create($this->uid, [
			'code' => 'BC-V-' . $suffix,
			'name' => 'Visible',
			'kind' => 'van',
		]);
		$hidden = $this->locations->create($this->uid, [
			'code' => 'BC-H-' . $suffix,
			'name' => 'Hidden',
			'kind' => 'site',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => 'BC-I-' . $suffix,
			'scanCode' => 'BC-I-' . $suffix,
			'name' => 'ACL Scan Item',
			'uom' => 'pcs',
			'reorderLevel' => 10,
		]);
		$this->movements->receive($this->uid, (int)$item['id'], (int)$hidden['id'], 20, 'hidden-stock');
		$this->movements->receive($this->uid, (int)$item['id'], (int)$visible['id'], 1, 'visible-stock');

		$this->config->setAppValue(Application::APP_ID, LocationAclService::KEY_ENABLED, '1');
		$this->acl->setForSubject('user', $fieldUid, [(int)$visible['id']]);

		$byCode = $this->items->byCode($fieldUid, 'BC-I-' . $suffix);
		$locIds = array_map(static fn (array $b): int => (int)$b['locationId'], $byCode['balances']);
		self::assertContains((int)$visible['id'], $locIds);
		self::assertNotContains((int)$hidden['id'], $locIds);

		// Org-wide sum is 21 (not low). Visible-only sum is 1 → field user sees low stock.
		$officeLow = $this->lowStock->list($this->uid, 200, 0);
		$officeIds = array_map(static fn (array $r): int => (int)$r['item']['id'], $officeLow['data']);
		self::assertNotContains((int)$item['id'], $officeIds);

		$fieldLow = $this->lowStock->list($fieldUid, 200, 0);
		$fieldIds = array_map(static fn (array $r): int => (int)$r['item']['id'], $fieldLow['data']);
		self::assertContains((int)$item['id'], $fieldIds);

		$this->acl->setForSubject('user', $fieldUid, []);
		$this->config->setAppValue(Application::APP_ID, LocationAclService::KEY_ENABLED, '0');
	}

	public function testFavouritesCannotAddHiddenLocation(): void
	{
		$users = Server::get(IUserManager::class);
		$fieldUid = 'iv-field-fav-' . bin2hex(random_bytes(2));
		if ($users->get($fieldUid) === null) {
			$users->createUser($fieldUid, bin2hex(random_bytes(8)));
		}

		$suffix = bin2hex(random_bytes(3));
		$visible = $this->locations->create($this->uid, [
			'code' => 'FAV-V-' . $suffix,
			'name' => 'Fav Visible',
			'kind' => 'van',
		]);
		$hidden = $this->locations->create($this->uid, [
			'code' => 'FAV-H-' . $suffix,
			'name' => 'Fav Hidden',
			'kind' => 'site',
		]);

		$this->config->setAppValue(Application::APP_ID, LocationAclService::KEY_ENABLED, '1');
		$this->acl->setForSubject('user', $fieldUid, [(int)$visible['id']]);

		$ok = $this->favourites->add($fieldUid, (int)$visible['id']);
		self::assertCount(1, $ok);

		try {
			$this->favourites->add($fieldUid, (int)$hidden['id']);
			self::fail('expected unknown_location');
		} catch (NotFoundException $e) {
			self::assertSame('unknown_location', $e->getErrorCode());
		}

		$list = $this->favourites->list($fieldUid);
		$ids = array_map(static fn (array $r): int => (int)$r['id'], $list);
		self::assertContains((int)$visible['id'], $ids);
		self::assertNotContains((int)$hidden['id'], $ids);

		$this->favourites->remove($fieldUid, (int)$visible['id']);
		$this->acl->setForSubject('user', $fieldUid, []);
		$this->config->setAppValue(Application::APP_ID, LocationAclService::KEY_ENABLED, '0');
	}

	public function testDeviceActorsRemainUnrestrictedWhenAclOn(): void
	{
		$this->config->setAppValue(Application::APP_ID, LocationAclService::KEY_ENABLED, '1');
		self::assertNull($this->acl->visibleLocationIds('device:42'));
		self::assertTrue($this->acl->canAccessLocation('device:42', 999999));
		$this->config->setAppValue(Application::APP_ID, LocationAclService::KEY_ENABLED, '0');
	}

	public function testCycleCountListAndGetHonourLocationAcl(): void
	{
		$users = Server::get(IUserManager::class);
		$fieldUid = 'iv-field-cc-' . bin2hex(random_bytes(2));
		if ($users->get($fieldUid) === null) {
			$users->createUser($fieldUid, bin2hex(random_bytes(8)));
		}
		$counts = (new Application())->getContainer()->get(\OCA\InventoryCheck\Service\CycleCountService::class);

		$suffix = bin2hex(random_bytes(3));
		$visible = $this->locations->create($this->uid, [
			'code' => 'CC-V-' . $suffix,
			'name' => 'CC Visible',
			'kind' => 'van',
		]);
		$hidden = $this->locations->create($this->uid, [
			'code' => 'CC-H-' . $suffix,
			'name' => 'CC Hidden',
			'kind' => 'site',
		]);
		$visCamp = $counts->create($this->uid, (int)$visible['id'], 'Vis ' . $suffix);
		$hidCamp = $counts->create($this->uid, (int)$hidden['id'], 'Hid ' . $suffix);

		$this->config->setAppValue(Application::APP_ID, LocationAclService::KEY_ENABLED, '1');
		$this->acl->setForSubject('user', $fieldUid, [(int)$visible['id']]);

		$list = $counts->list($fieldUid, null, 50, 0);
		$ids = array_map(static fn (array $r): int => (int)$r['id'], $list['data']);
		self::assertContains((int)$visCamp['id'], $ids);
		self::assertNotContains((int)$hidCamp['id'], $ids);

		$ok = $counts->get($fieldUid, (int)$visCamp['id']);
		self::assertSame((int)$visCamp['id'], (int)$ok['id']);

		try {
			$counts->get($fieldUid, (int)$hidCamp['id']);
			self::fail('expected unknown_location');
		} catch (NotFoundException $e) {
			self::assertSame('unknown_location', $e->getErrorCode());
		}

		$this->acl->setForSubject('user', $fieldUid, []);
		$this->config->setAppValue(Application::APP_ID, LocationAclService::KEY_ENABLED, '0');
	}

	public function testCsvExportGermanHeadersRoundTripViaImportKeys(): void
	{
		$pack = $this->export->export($this->uid, 'items', null, null, null, null, 'de');
		self::assertStringContainsString('Artikelnummer', $pack['body']);
		self::assertStringContainsString('Bezeichnung', $pack['body']);
		self::assertStringContainsString('Mindestbestand', $pack['body']);
		// Canonicalize must map DE headers back so import can consume a DE export.
		$firstLine = explode("\n", preg_replace('/^\xEF\xBB\xBF/', '', $pack['body']) ?? $pack['body'])[0];
		self::assertStringContainsString('Artikelnummer', $firstLine);
	}
}
