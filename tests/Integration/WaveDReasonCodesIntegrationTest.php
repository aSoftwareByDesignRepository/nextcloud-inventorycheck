<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\MovementService;
use OCA\InventoryCheck\Service\ReasonCodes;
use OCP\IConfig;
use OCP\Server;
use Test\TestCase;

/**
 * Wave D3 — adjust reason-code policy (require_adjust_reason).
 *
 * @group DB
 */
final class WaveDReasonCodesIntegrationTest extends TestCase
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
		$this->prevRequired = ReasonCodes::isRequired($this->config);
	}

	protected function tearDown(): void
	{
		ReasonCodes::setRequired($this->config, $this->prevRequired);
		parent::tearDown();
	}

	public function testAdjustWithoutReasonCodeRejectedWhenRequired(): void
	{
		ReasonCodes::setRequired($this->config, true);
		[$itemId, $locId] = $this->seedPair('REQ');
		$this->movements->receive($this->uid, $itemId, $locId, 2, null);

		try {
			$this->movements->adjust($this->uid, $itemId, $locId, 'set', 1, null, 'note');
			$this->fail('expected reason_code_required');
		} catch (ValidationException $e) {
			$this->assertSame('validation_failed', $e->getErrorCode());
			$this->assertSame('reason_code_required', $e->getDetails()[0]['code'] ?? null);
		}
	}

	public function testAdjustWithCatalogReasonSucceedsWhenRequired(): void
	{
		ReasonCodes::setRequired($this->config, true);
		[$itemId, $locId] = $this->seedPair('OK');
		$this->movements->receive($this->uid, $itemId, $locId, 4, null);
		$result = $this->movements->adjust(
			$this->uid,
			$itemId,
			$locId,
			'set',
			2,
			null,
			'counted',
			null,
			true,
			'inventur',
		);
		$this->assertSame(2, $result['balances'][0]['qty']);
		$this->assertSame('inventur', $result['movements'][0]['reasonCode'] ?? null);
	}

	public function testAdjustWithoutReasonAllowedWhenPolicyOff(): void
	{
		ReasonCodes::setRequired($this->config, false);
		[$itemId, $locId] = $this->seedPair('OFF');
		$this->movements->receive($this->uid, $itemId, $locId, 3, null);
		$result = $this->movements->adjust($this->uid, $itemId, $locId, 'set', 1, null, 'optional');
		$this->assertSame(1, $result['balances'][0]['qty']);
	}

	/** @return array{0:int,1:int} */
	private function seedPair(string $tag): array
	{
		$suffix = $tag . '-' . bin2hex(random_bytes(3));
		$loc = $this->locations->create($this->uid, [
			'code' => 'RC-L-' . $suffix,
			'name' => 'Reason loc',
			'kind' => 'shelf',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => 'RC-I-' . $suffix,
			'name' => 'Reason item',
		]);
		return [(int)$item['id'], (int)$loc['id']];
	}
}
