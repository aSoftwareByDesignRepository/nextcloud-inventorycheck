<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\LocationAclService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\MovementService;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Server;
use Test\TestCase;

/**
 * IV-TOTAL-STOCK — AC-8/9/10/17: identical data, actor-scoped totals.
 *
 * @group DB
 */
final class TotalStockOverviewIntegrationTest extends TestCase
{
	private string $uid = 'admin';
	private LocationService $locations;
	private ItemService $items;
	private MovementService $movements;
	private LocationAclService $acl;
	private IConfig $config;

	protected function setUp(): void
	{
		parent::setUp();
		$app = new Application();
		$c = $app->getContainer();
		$this->locations = $c->get(LocationService::class);
		$this->items = $c->get(ItemService::class);
		$this->movements = $c->get(MovementService::class);
		$this->acl = $c->get(LocationAclService::class);
		$this->config = Server::get(IConfig::class);
	}

	protected function tearDown(): void
	{
		$this->config->setAppValue(Application::APP_ID, LocationAclService::KEY_ENABLED, '0');
		parent::tearDown();
	}

	private function fieldUser(string $prefix): string
	{
		$users = Server::get(IUserManager::class);
		$fieldUid = $prefix . bin2hex(random_bytes(3));
		if ($users->get($fieldUid) === null) {
			$users->createUser($fieldUid, bin2hex(random_bytes(8)));
		}
		return $fieldUid;
	}

	public function testOfficeGlobalTotalVsFieldScopedTotal(): void
	{
		$fieldUid = $this->fieldUser('iv-ts-');
		$suffix = bin2hex(random_bytes(3));
		$visible = $this->locations->create($this->uid, [
			'code' => 'TS-V-' . $suffix, 'name' => 'Visible', 'kind' => 'van',
		]);
		$hidden = $this->locations->create($this->uid, [
			'code' => 'TS-H-' . $suffix, 'name' => 'Hidden', 'kind' => 'site',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => 'TS-I-' . $suffix, 'name' => 'Total Stock Item', 'uom' => 'pcs',
		]);
		$itemId = (int)$item['id'];
		$this->movements->receive($this->uid, $itemId, (int)$visible['id'], 5, 'stock');
		$this->movements->receive($this->uid, $itemId, (int)$hidden['id'], 99, 'stock');

		$this->config->setAppValue(Application::APP_ID, LocationAclService::KEY_ENABLED, '1');
		$this->acl->setForSubject('user', $fieldUid, [(int)$visible['id']]);

		// Office: global truth (AC-10).
		$officeRow = $this->items->list($this->uid, 'TS-I-' . $suffix, null, false, 5, 0)['data'][0];
		self::assertSame(104, $officeRow['totalQty']);
		self::assertSame(104, $this->items->get($this->uid, $itemId)['totalQty']);

		// Field: scoped truth (AC-8) — present, never stripped (AC-17).
		$fieldRow = $this->items->list($fieldUid, 'TS-I-' . $suffix, null, false, 5, 0)['data'][0];
		self::assertSame(5, $fieldRow['totalQty']);
		self::assertSame(5, $this->items->get($fieldUid, $itemId)['totalQty']);

		$this->acl->setForSubject('user', $fieldUid, []);
	}

	public function testFieldUserWithNoGrantsSeesZero(): void
	{
		$fieldUid = $this->fieldUser('iv-ts0-');
		$suffix = bin2hex(random_bytes(3));
		$loc = $this->locations->create($this->uid, [
			'code' => 'TS0-L-' . $suffix, 'name' => 'Loc', 'kind' => 'warehouse',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => 'TS0-I-' . $suffix, 'name' => 'Zero Grant Item', 'uom' => 'pcs',
		]);
		$itemId = (int)$item['id'];
		$this->movements->receive($this->uid, $itemId, (int)$loc['id'], 40, 'stock');

		$this->config->setAppValue(Application::APP_ID, LocationAclService::KEY_ENABLED, '1');

		// No grants at all → visible set is empty (AC-9).
		$fieldRow = $this->items->list($fieldUid, 'TS0-I-' . $suffix, null, false, 5, 0)['data'][0] ?? null;
		if ($fieldRow !== null) {
			self::assertSame(0, $fieldRow['totalQty']);
		}
		self::assertSame(0, $this->items->get($fieldUid, $itemId)['totalQty']);
	}
}
