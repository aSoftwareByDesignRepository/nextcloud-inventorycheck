<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\LocationAclService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\LotExpiryNotifyService;
use OCA\InventoryCheck\Service\LotService;
use OCA\InventoryCheck\Service\MovementService;
use OCA\InventoryCheck\Service\RestockConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Server;
use Test\TestCase;

/**
 * IV-LOT-EXPIRY-PACK — AC-12 dedupe, AC-15 server-side FEFO ordering,
 * ACL-scoped lot qty, office-only recipients (NN-02).
 *
 * @group DB
 */
final class LotExpiryPackIntegrationTest extends TestCase
{
	private string $uid = 'admin';
	private LocationService $locations;
	private ItemService $items;
	private MovementService $movements;
	private LocationAclService $acl;
	private LotService $lotService;
	private LotExpiryNotifyService $expiry;
	private IConfig $config;

	protected function setUp(): void
	{
		parent::setUp();
		$c = (new Application())->getContainer();
		$this->locations = $c->get(LocationService::class);
		$this->items = $c->get(ItemService::class);
		$this->movements = $c->get(MovementService::class);
		$this->acl = $c->get(LocationAclService::class);
		$this->lotService = $c->get(LotService::class);
		$this->expiry = $c->get(LotExpiryNotifyService::class);
		$this->config = Server::get(IConfig::class);
	}

	protected function tearDown(): void
	{
		$this->config->setAppValue(Application::APP_ID, LocationAclService::KEY_ENABLED, '0');
		$this->config->setAppValue(Application::APP_ID, RestockConfig::KEY_EXPIRY_WARN_DAYS, '30');
		parent::tearDown();
	}

	private function fieldUser(string $prefix): string
	{
		$users = Server::get(IUserManager::class);
		$uid = $prefix . bin2hex(random_bytes(3));
		if ($users->get($uid) === null) {
			$users->createUser($uid, bin2hex(random_bytes(8)));
		}
		return $uid;
	}

	public function testFefoPayloadIsServerSortedAndAclScoped(): void
	{
		$fieldUid = $this->fieldUser('iv-le-');
		$suffix = bin2hex(random_bytes(3));
		$visible = $this->locations->create($this->uid, [
			'code' => 'LE-V-' . $suffix, 'name' => 'Visible', 'kind' => 'van',
		]);
		$hidden = $this->locations->create($this->uid, [
			'code' => 'LE-H-' . $suffix, 'name' => 'Hidden', 'kind' => 'site',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => 'LE-I-' . $suffix, 'name' => 'Expiry Item', 'uom' => 'pcs',
			'trackMode' => 'lot',
		]);
		$itemId = (int)$item['id'];
		$now = time();
		$this->movements->receive($this->uid, $itemId, (int)$visible['id'], 3, 'stock', 'LOT-LATE', true, null, $now + 40 * 86400);
		$this->movements->receive($this->uid, $itemId, (int)$visible['id'], 5, 'stock', 'LOT-EARLY', true, null, $now + 5 * 86400);
		$this->movements->receive($this->uid, $itemId, (int)$hidden['id'], 50, 'stock', 'LOT-EARLY', true, null, $now + 5 * 86400);

		// AC-15: server-sorted — earliest expiry first.
		$officeRows = $this->lotService->lotsWithStock($this->uid, $itemId);
		self::assertSame('LOT-EARLY', $officeRows[0]['lotCode']);
		self::assertSame(55, $officeRows[0]['qty']);
		self::assertSame('expiring', $officeRows[0]['state']);
		self::assertSame('LOT-LATE', $officeRows[1]['lotCode']);
		self::assertSame(3, $officeRows[1]['qty']);

		// ACL: field user sees only the visible location's qty.
		$this->config->setAppValue(Application::APP_ID, LocationAclService::KEY_ENABLED, '1');
		$this->acl->setForSubject('user', $fieldUid, [(int)$visible['id']]);
		$fieldRows = $this->lotService->lotsWithStock($fieldUid, $itemId);
		self::assertSame(5, $fieldRows[0]['qty']);
		self::assertSame(3, $fieldRows[1]['qty']);

		$this->acl->setForSubject('user', $fieldUid, []);
	}

	public function testJobDedupesPerLotRecipientDay(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$loc = $this->locations->create($this->uid, [
			'code' => 'LEJ-L-' . $suffix, 'name' => 'Job Loc', 'kind' => 'warehouse',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => 'LEJ-I-' . $suffix, 'name' => 'Job Item', 'uom' => 'pcs',
			'trackMode' => 'lot',
		]);
		$itemId = (int)$item['id'];
		$this->movements->receive(
			$this->uid, $itemId, (int)$loc['id'], 4, 'stock', 'LOT-JOB', true, null, time() + 10 * 86400,
		);
		// Office recipient = admin (NN-02: office only).
		$this->config->setAppValue(
			Application::APP_ID, AccessControlService::KEY_OFFICE_USER_IDS, '["admin"]',
		);
		$this->config->setAppValue(Application::APP_ID, RestockConfig::KEY_EXPIRY_WARN_DAYS, '30');

		$this->expiry->run();
		self::assertSame(1, $this->countLotexpLogs($itemId),
			'first run must log lotexp:<lotId>:admin:<day>');

		$before = $this->countLotexpLogs($itemId);
		$this->expiry->run(); // AC-12: second run same day = no-op
		self::assertSame($before, $this->countLotexpLogs($itemId));

		// Watch off → no rows at all.
		$this->config->setAppValue(Application::APP_ID, RestockConfig::KEY_EXPIRY_WARN_DAYS, '0');
		$item2 = $this->items->create($this->uid, [
			'sku' => 'LEJ2-' . $suffix, 'name' => 'Off Item', 'uom' => 'pcs',
			'trackMode' => 'lot',
		]);
		$this->movements->receive(
			$this->uid, (int)$item2['id'], (int)$loc['id'], 2, 'stock', 'LOT-OFF', true, null, time() + 10 * 86400,
		);
		$this->expiry->run();
		self::assertSame(0, $this->countLotexpLogs((int)$item2['id']));

		$this->config->setAppValue(Application::APP_ID, AccessControlService::KEY_OFFICE_USER_IDS, '[]');
	}

	private function countLotexpLogs(int $itemId): int
	{
		$db = Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->selectAlias($qb->func()->count('*'), 'n')
			->from('iv_notif_log')
			->where($qb->expr()->eq('item_id', $qb->createNamedParameter($itemId)))
			->andWhere($qb->expr()->like('dedupe_key', $qb->createNamedParameter('lotexp:%')));
		return (int)$qb->executeQuery()->fetchOne();
	}
}
