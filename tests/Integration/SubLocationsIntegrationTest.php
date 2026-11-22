<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Exception\ConflictException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\LocationAclService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\MovementService;
use OCA\InventoryCheck\Service\ItemService;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Server;
use Test\TestCase;

/**
 * IV-SUB-LOCATIONS — real-DB: parent grant ⇒ subtree visible (AC-5),
 * child grant stays narrow (AC-6), pathLabel in list payloads,
 * delete-refuse + depth cap end-to-end.
 *
 * @group DB
 */
final class SubLocationsIntegrationTest extends TestCase
{
	private string $uid = 'admin';
	private LocationService $locations;
	private LocationAclService $acl;
	private ItemService $items;
	private MovementService $movements;
	private IConfig $config;

	protected function setUp(): void
	{
		parent::setUp();
		$c = (new Application())->getContainer();
		$this->locations = $c->get(LocationService::class);
		$this->acl = $c->get(LocationAclService::class);
		$this->items = $c->get(ItemService::class);
		$this->movements = $c->get(MovementService::class);
		$this->config = Server::get(IConfig::class);
	}

	protected function tearDown(): void
	{
		$this->config->setAppValue(Application::APP_ID, LocationAclService::KEY_ENABLED, '0');
		parent::tearDown();
	}

	private function fieldUser(): string
	{
		$users = Server::get(IUserManager::class);
		$uid = 'iv-sub-' . bin2hex(random_bytes(3));
		if ($users->get($uid) === null) {
			$users->createUser($uid, bin2hex(random_bytes(8)));
		}
		return $uid;
	}

	private function parentChild(): array
	{
		$suffix = bin2hex(random_bytes(3));
		$parent = $this->locations->create($this->uid, [
			'code' => 'SUB-P-' . $suffix, 'name' => 'Warehouse ' . $suffix, 'kind' => 'warehouse',
		]);
		$child = $this->locations->create($this->uid, [
			'code' => 'SUB-C-' . $suffix, 'name' => 'Shelf ' . $suffix, 'kind' => 'shelf',
			'parentId' => (int)$parent['id'],
		]);
		return [$parent, $child];
	}

	public function testPathLabelInListPayload(): void
	{
		[$parent, $child] = $this->parentChild();
		$list = $this->locations->list($this->uid, null, 100, 0, 'SUB-');
		$byId = [];
		foreach ($list['data'] as $row) {
			$byId[(int)$row['id']] = $row;
		}
		$this->assertSame($parent['name'] . ' / ' . $child['name'], $byId[(int)$child['id']]['pathLabel']);
		$this->assertSame($parent['name'], $byId[(int)$parent['id']]['pathLabel']);
	}

	public function testDepthCapEndToEnd(): void
	{
		[, $child] = $this->parentChild();
		$this->expectException(ValidationException::class);
		$this->locations->create($this->uid, [
			'code' => 'SUB-G-' . bin2hex(random_bytes(3)), 'name' => 'Grandchild',
			'kind' => 'shelf', 'parentId' => (int)$child['id'],
		]);
	}

	public function testDeleteParentWithChildrenRefused(): void
	{
		[$parent] = $this->parentChild();
		try {
			$this->locations->delete($this->uid, (int)$parent['id']);
			$this->fail('expected location_has_children');
		} catch (ConflictException $e) {
			$this->assertSame('location_has_children', $e->getMessage());
		}
	}

	public function testParentGrantCoversChildAcl(): void
	{
		[$parent, $child] = $this->parentChild();
		$field = $this->fieldUser();
		$this->config->setAppValue(Application::APP_ID, LocationAclService::KEY_ENABLED, '1');
		$this->acl->setForSubject('user', $field, [(int)$parent['id']]);
		$visible = $this->acl->visibleLocationIds($field);
		$this->assertContains((int)$parent['id'], $visible);
		$this->assertContains((int)$child['id'], $visible, 'parent grant must cover the child (AC-5)');
	}

	public function testChildGrantStaysNarrow(): void
	{
		[$parent, $child] = $this->parentChild();
		// Second child under the same parent — must NOT become visible.
		$sibling = $this->locations->create($this->uid, [
			'code' => 'SUB-S-' . bin2hex(random_bytes(3)), 'name' => 'Sibling',
			'kind' => 'shelf', 'parentId' => (int)$parent['id'],
		]);
		$field = $this->fieldUser();
		$this->config->setAppValue(Application::APP_ID, LocationAclService::KEY_ENABLED, '1');
		$this->acl->setForSubject('user', $field, [(int)$child['id']]);
		$visible = $this->acl->visibleLocationIds($field);
		$this->assertContains((int)$child['id'], $visible);
		$this->assertNotContains((int)$parent['id'], $visible, 'child grant must not leak the parent');
		$this->assertNotContains((int)$sibling['id'], $visible, 'child grant must not leak siblings (AC-6)');
	}

	public function testReparentUpdatesPathLabel(): void
	{
		[$parent, $child] = $this->parentChild();
		$other = $this->locations->create($this->uid, [
			'code' => 'SUB-O-' . bin2hex(random_bytes(3)), 'name' => 'Other Wh',
			'kind' => 'warehouse',
		]);
		$api = $this->locations->update($this->uid, (int)$child['id'], ['parentId' => (int)$other['id']]);
		$this->assertSame('Other Wh / ' . $child['name'], $api['pathLabel']);
	}

	public function testRootsEndpointPayload(): void
	{
		[$parent, $child] = $this->parentChild();
		$roots = $this->locations->roots($this->uid);
		$ids = array_map(static fn (array $r): int => (int)$r['id'], $roots);
		$this->assertContains((int)$parent['id'], $ids);
		$this->assertNotContains((int)$child['id'], $ids, 'roots picker must exclude children');
	}
}
