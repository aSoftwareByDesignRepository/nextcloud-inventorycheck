<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\MovementService;
use OCP\IConfig;
use OCP\Server;
use Test\TestCase;

/**
 * S16 reason length, S19 string bounds, S16 ref_type/ref_id not writable via API.
 *
 * @group DB
 */
final class ValidationContractsIntegrationTest extends TestCase
{
	private MovementService $movements;
	private LocationService $locations;
	private ItemService $items;
	private string $uid = 'admin';

	protected function setUp(): void
	{
		parent::setUp();
		$app = new Application();
		$c = $app->getContainer();
		$this->movements = $c->get(MovementService::class);
		$this->locations = $c->get(LocationService::class);
		$this->items = $c->get(ItemService::class);
		Server::get(IConfig::class)->setAppValue(
			Application::APP_ID,
			AccessControlService::KEY_ALLOW_NEGATIVE,
			'0',
		);
	}

	public function testReasonOver512Rejected(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$loc = $this->locations->create($this->uid, [
			'code' => 'VR-' . $suffix, 'name' => 'Loc', 'kind' => 'other',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => 'VRI-' . $suffix, 'name' => 'Item',
		]);
		try {
			$this->movements->receive(
				$this->uid,
				(int)$item['id'],
				(int)$loc['id'],
				1,
				str_repeat('x', 513),
			);
			$this->fail('expected validation_failed for long reason');
		} catch (ValidationException $e) {
			$this->assertSame('validation_failed', $e->getErrorCode());
			$this->assertSame('reason', $e->getDetails()[0]['field'] ?? null);
		}
	}

	public function testReasonExactly512Accepted(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$loc = $this->locations->create($this->uid, [
			'code' => 'VR2-' . $suffix, 'name' => 'Loc', 'kind' => 'other',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => 'VRI2-' . $suffix, 'name' => 'Item',
		]);
		$result = $this->movements->receive(
			$this->uid,
			(int)$item['id'],
			(int)$loc['id'],
			1,
			str_repeat('y', 512),
		);
		$this->assertSame(512, mb_strlen((string)$result['movements'][0]['reason']));
	}

	public function testNameOver255Rejected(): void
	{
		$suffix = bin2hex(random_bytes(3));
		try {
			$this->items->create($this->uid, [
				'sku' => 'VN-' . $suffix,
				'name' => str_repeat('n', 256),
			]);
			$this->fail('expected name length rejection');
		} catch (ValidationException $e) {
			$this->assertSame('validation_failed', $e->getErrorCode());
			$this->assertSame('name', $e->getDetails()[0]['field'] ?? null);
		}
	}

	public function testDescriptionOver10000Rejected(): void
	{
		$suffix = bin2hex(random_bytes(3));
		try {
			$this->items->create($this->uid, [
				'sku' => 'VD-' . $suffix,
				'name' => 'Desc item',
				'description' => str_repeat('d', 10001),
			]);
			$this->fail('expected description length rejection');
		} catch (ValidationException $e) {
			$this->assertSame('validation_failed', $e->getErrorCode());
			$this->assertSame('description', $e->getDetails()[0]['field'] ?? null);
		}
	}

	public function testMovementApiNeverAcceptsRefTypeOrRefId(): void
	{
		$src = (string)file_get_contents(
			dirname(__DIR__, 2) . '/lib/Controller/MovementController.php',
		);
		$this->assertStringNotContainsString('refType', $src);
		$this->assertStringNotContainsString('ref_type', $src);
		$this->assertStringNotContainsString('refId', $src);
		$this->assertStringNotContainsString('ref_id', $src);

		$svc = (string)file_get_contents(
			dirname(__DIR__, 2) . '/lib/Service/MovementService.php',
		);
		// S16: ref_type/ref_id columns exist but every public movement path
		// (receive/issue/transfer/adjust/scan) writes NULL by omitting the
		// insertMovement() ref args, which default to null. Only the
		// server-only issueWithRef() flange path (Wave B2) — never exposed
		// via MovementController — forwards real ref values, and it
		// validates them itself before writing.
		$this->assertStringContainsString('?string $refType = null', $svc);
		$this->assertStringContainsString('?int $refId = null', $svc);
		$this->assertSame(
			2,
			substr_count($svc, '$refType, $refId'),
			'the $refType/$refId pair (closure capture + insertMovement call) must appear only inside issueWithRef()',
		);

		$issueWithRefStart = strpos($svc, 'function issueWithRef(');
		$this->assertNotFalse($issueWithRefStart, 'issueWithRef() must exist');
		// Skip past nested/anonymous closures (e.g. `function () use (`) and only
		// match the next top-level class method, which is indented by a single tab.
		$nextMethod = preg_match(
			'/\n\t(?:public|private|protected) function /',
			$svc,
			$matches,
			PREG_OFFSET_CAPTURE,
			$issueWithRefStart + strlen('function issueWithRef('),
		) ? $matches[0][1] : false;
		$issueWithRefBody = $nextMethod !== false
			? substr($svc, $issueWithRefStart, $nextMethod - $issueWithRefStart)
			: substr($svc, $issueWithRefStart);
		$this->assertSame(
			2,
			substr_count($issueWithRefBody, '$refType, $refId'),
			'both $refType/$refId occurrences must be confined to issueWithRef()',
		);
	}

	public function testInsertedMovementPersistsNullRefColumns(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$loc = $this->locations->create($this->uid, [
			'code' => 'VR-' . $suffix,
			'name' => 'Ref loc',
			'kind' => 'warehouse',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => 'VR-' . $suffix,
			'name' => 'Ref item',
		]);
		$result = $this->movements->receive(
			$this->uid,
			(int)$item['id'],
			(int)$loc['id'],
			3,
			'ref-null-check',
		);
		$movementId = (int)($result['movements'][0]['id'] ?? 0);
		$this->assertGreaterThan(0, $movementId);

		$db = \OCP\Server::get(\OCP\IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->select('ref_type', 'ref_id')->from('iv_movements')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($movementId, \PDO::PARAM_INT)));
		$row = $qb->executeQuery()->fetchAssociative();
		$this->assertIsArray($row);
		$this->assertTrue(
			$row['ref_type'] === null || $row['ref_type'] === '',
			'ref_type must be NULL in DB',
		);
		$this->assertTrue(
			$row['ref_id'] === null || $row['ref_id'] === '',
			'ref_id must be NULL in DB',
		);
	}
}
