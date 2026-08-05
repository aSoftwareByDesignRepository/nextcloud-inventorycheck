<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\LocationScanPolicy;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\MovementService;
use OCP\IConfig;
use OCP\Server;
use Test\TestCase;

/**
 * Wave D8 / AF-IV12 — require_location_scan on issue + transfer (not only /scan).
 *
 * @group DB
 */
final class WaveDLocationScanIntegrationTest extends TestCase
{
	private MovementService $movements;
	private LocationService $locations;
	private ItemService $items;
	private IConfig $config;
	private string $uid = 'admin';
	private bool $prevRequired;

	protected function setUp(): void
	{
		parent::setUp();
		$app = new Application();
		$c = $app->getContainer();
		$this->movements = $c->get(MovementService::class);
		$this->locations = $c->get(LocationService::class);
		$this->items = $c->get(ItemService::class);
		$this->config = Server::get(IConfig::class);
		$this->config->setAppValue(Application::APP_ID, AccessControlService::KEY_ALLOW_NEGATIVE, '0');
		$this->prevRequired = LocationScanPolicy::isRequired($this->config);
	}

	protected function tearDown(): void
	{
		LocationScanPolicy::setRequired($this->config, $this->prevRequired);
		parent::tearDown();
	}

	public function testIssueWithoutLocationCodeRejectedWhenRequired(): void
	{
		LocationScanPolicy::setRequired($this->config, true);
		[$itemId, $locId] = $this->seedPair('ISS');
		$this->movements->receive($this->uid, $itemId, $locId, 5, null);

		try {
			$this->movements->issue($this->uid, $itemId, $locId, 1, null);
			$this->fail('expected location_code_required');
		} catch (ValidationException $e) {
			$this->assertSame('location_code_required', $e->getDetails()[0]['code'] ?? null);
		}
	}

	public function testIssueWithMatchingCodeSucceedsWhenRequired(): void
	{
		LocationScanPolicy::setRequired($this->config, true);
		[$itemId, $locId, $locCode] = $this->seedPair('OKI');
		$this->movements->receive($this->uid, $itemId, $locId, 4, null);
		$result = $this->movements->issue($this->uid, $itemId, $locId, 1, null, null, $locCode);
		$this->assertSame(3, $result['balances'][0]['qty']);
	}

	public function testTransferRequiresBothEndCodesWhenRequired(): void
	{
		LocationScanPolicy::setRequired($this->config, true);
		[$itemId, $fromId, $fromCode] = $this->seedPair('TF');
		$to = $this->locations->create($this->uid, [
			'code' => 'TO-' . bin2hex(random_bytes(3)),
			'name' => 'Dest',
			'kind' => 'van',
		]);
		$toId = (int)$to['id'];
		$toCode = (string)$to['code'];
		$this->movements->receive($this->uid, $itemId, $fromId, 6, null);

		try {
			$this->movements->transfer($this->uid, $itemId, $fromId, $toId, 1, null, null, $fromCode, null);
			$this->fail('expected destination location_code_required');
		} catch (ValidationException $e) {
			$this->assertSame('toLocationCode', $e->getDetails()[0]['field'] ?? null);
			$this->assertSame('location_code_required', $e->getDetails()[0]['code'] ?? null);
		}

		$result = $this->movements->transfer(
			$this->uid,
			$itemId,
			$fromId,
			$toId,
			2,
			null,
			null,
			$fromCode,
			$toCode,
		);
		$this->assertCount(2, $result['movements']);
	}

	public function testIssueWithoutCodeAllowedWhenPolicyOff(): void
	{
		LocationScanPolicy::setRequired($this->config, false);
		[$itemId, $locId] = $this->seedPair('OFF');
		$this->movements->receive($this->uid, $itemId, $locId, 3, null);
		$result = $this->movements->issue($this->uid, $itemId, $locId, 1, null);
		$this->assertSame(2, $result['balances'][0]['qty']);
	}

	/** @return array{0:int,1:int,2:string} */
	private function seedPair(string $tag): array
	{
		$suffix = $tag . '-' . bin2hex(random_bytes(3));
		$loc = $this->locations->create($this->uid, [
			'code' => 'L-' . $suffix,
			'name' => 'Loc ' . $suffix,
			'kind' => 'warehouse',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => 'S-' . $suffix,
			'name' => 'Item ' . $suffix,
			'uom' => 'pcs',
		]);
		return [(int)$item['id'], (int)$loc['id'], (string)$loc['code']];
	}
}
