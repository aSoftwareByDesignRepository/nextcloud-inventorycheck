<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Exception\PermissionDeniedException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\LowStockService;
use OCA\InventoryCheck\Service\MovementService;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Server;
use Test\TestCase;

/**
 * AC-13 low-stock boundaries + AC-19 scan kind ACL.
 *
 * @group DB
 */
final class LowStockAndScanAclIntegrationTest extends TestCase
{
	private MovementService $movements;
	private LocationService $locations;
	private ItemService $items;
	private LowStockService $lowStock;
	private IConfig $config;
	private string $uid = 'admin';

	protected function setUp(): void
	{
		parent::setUp();
		$app = new Application();
		$c = $app->getContainer();
		$this->movements = $c->get(MovementService::class);
		$this->locations = $c->get(LocationService::class);
		$this->items = $c->get(ItemService::class);
		$this->lowStock = $c->get(LowStockService::class);
		$this->config = Server::get(IConfig::class);
		$this->config->setAppValue(Application::APP_ID, AccessControlService::KEY_ALLOW_NEGATIVE, '0');
	}

	public function testLowStockBoundariesAc13(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$loc = $this->locations->create($this->uid, [
			'code' => 'LSX-' . $suffix, 'name' => 'Loc', 'kind' => 'other',
		]);
		$locId = (int)$loc['id'];

		$below = $this->items->create($this->uid, [
			'sku' => 'LS-BELOW-' . $suffix, 'name' => 'Below', 'reorderLevel' => 5,
		]);
		$equal = $this->items->create($this->uid, [
			'sku' => 'LS-EQ-' . $suffix, 'name' => 'Equal', 'reorderLevel' => 5,
		]);
		$zero = $this->items->create($this->uid, [
			'sku' => 'LS-Z-' . $suffix, 'name' => 'Zero reorder', 'reorderLevel' => 0,
		]);
		$inactive = $this->items->create($this->uid, [
			'sku' => 'LS-IN-' . $suffix, 'name' => 'Inactive', 'reorderLevel' => 5,
		]);

		$this->movements->receive($this->uid, (int)$below['id'], $locId, 4, null);
		$this->movements->receive($this->uid, (int)$equal['id'], $locId, 5, null);
		$this->movements->receive($this->uid, (int)$zero['id'], $locId, 1, null);
		$this->movements->receive($this->uid, (int)$inactive['id'], $locId, 1, null);
		$this->movements->adjust($this->uid, (int)$inactive['id'], $locId, 'set', 0, null, 'clear');
		$this->items->update($this->uid, (int)$inactive['id'], ['active' => false]);

		$list = $this->lowStock->list(200, 0);
		$ids = array_map(static fn (array $r): int => (int)$r['item']['id'], $list['data']);
		$this->assertContains((int)$below['id'], $ids);
		$this->assertNotContains((int)$equal['id'], $ids);
		$this->assertNotContains((int)$zero['id'], $ids);
		$this->assertNotContains((int)$inactive['id'], $ids);
	}

	public function testScanReceiveAclFieldDeniedOfficeAllowed(): void
	{
		$users = Server::get(IUserManager::class);
		$fieldUid = 'ivscan_' . bin2hex(random_bytes(2));
		if ($users->userExists($fieldUid)) {
			$users->get($fieldUid)?->delete();
		}
		$this->assertNotFalse($users->createUser($fieldUid, bin2hex(random_bytes(8))));

		$prevRestriction = $this->config->getAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_RESTRICTION, '0');
		$prevAllowed = $this->config->getAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS, '[]');
		$prevOffice = $this->config->getAppValue(Application::APP_ID, AccessControlService::KEY_OFFICE_USER_IDS, '[]');

		try {
			$this->config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_RESTRICTION, '1');
			$this->config->setAppValue(
				Application::APP_ID,
				AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS,
				json_encode([$fieldUid, 'admin'], JSON_THROW_ON_ERROR),
			);
			$this->config->setAppValue(Application::APP_ID, AccessControlService::KEY_OFFICE_USER_IDS, '[]');

			$suffix = bin2hex(random_bytes(3));
			$loc = $this->locations->create($this->uid, [
				'code' => 'SCX-' . $suffix, 'name' => 'Loc', 'kind' => 'van',
			]);
			$item = $this->items->create($this->uid, [
				'sku' => 'SCI-' . $suffix,
				'scanCode' => 'SCSCAN-' . $suffix,
				'name' => 'Scan item',
			]);
			$itemId = (int)$item['id'];
			$locId = (int)$loc['id'];

			try {
				$this->movements->scan($fieldUid, 'SCSCAN-' . $suffix, 'receive', $locId, null, 1, null, null, false);
				$this->fail('field scan receive must 403');
			} catch (PermissionDeniedException) {
				// expected
			}

			$ok = $this->movements->scan($this->uid, 'SCSCAN-' . $suffix, 'receive', $locId, null, 2, null, null, true);
			$this->assertSame(2, $ok['balances'][0]['qty']);

			try {
				$this->movements->scan($this->uid, 'SCSCAN-' . $suffix, 'transfer', $locId, null, 1, null, null, true);
				$this->fail('scan transfer without toLocationId must 422');
			} catch (ValidationException $e) {
				$this->assertSame('validation_failed', $e->getErrorCode());
			}

			$this->expectException(ValidationException::class);
			$this->movements->scan($this->uid, 'SCSCAN-' . $suffix, 'transfer', $locId, $locId, 1, null, null, true);
		} finally {
			$this->config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_RESTRICTION, $prevRestriction);
			$this->config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS, $prevAllowed);
			$this->config->setAppValue(Application::APP_ID, AccessControlService::KEY_OFFICE_USER_IDS, $prevOffice);
			$users->get($fieldUid)?->delete();
		}
	}
}
