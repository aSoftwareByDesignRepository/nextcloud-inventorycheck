<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\MovementService;
use OCA\InventoryCheck\Service\Pagination;
use Test\TestCase;

/**
 * I7: pagination + from>to + movement sort (S9 / S11).
 *
 * @group DB
 */
final class QueryValidationIntegrationTest extends TestCase
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
	}

	public function testFromGreaterThanToRejected(): void
	{
		$this->expectException(ValidationException::class);
		try {
			$this->movements->list(null, null, null, 200, 100, null, 50, 0);
		} catch (ValidationException $e) {
			$this->assertSame('invalid_query', $e->getErrorCode());
			throw $e;
		}
	}

	public function testPaginationRejectsOverMaxLimit(): void
	{
		$this->expectException(ValidationException::class);
		Pagination::parse(201, 0);
	}

	public function testMovementsSortNewestFirst(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$loc = $this->locations->create($this->uid, [
			'code' => 'QV-' . $suffix, 'name' => 'Loc', 'kind' => 'other',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => 'QVI-' . $suffix, 'name' => 'Item',
		]);
		$itemId = (int)$item['id'];
		$locId = (int)$loc['id'];

		$this->movements->receive($this->uid, $itemId, $locId, 1, 'first');
		usleep(1100000); // ensure created_at differs on second-resolution clocks
		$this->movements->receive($this->uid, $itemId, $locId, 1, 'second');

		$list = $this->movements->list(null, $itemId, $locId, null, null, null, 50, 0);
		$this->assertGreaterThanOrEqual(2, $list['total']);
		$first = $list['data'][0];
		$second = $list['data'][1];
		$this->assertGreaterThanOrEqual(
			(int)$second['createdAt'],
			(int)$first['createdAt'],
			'created_at DESC',
		);
		if ((int)$first['createdAt'] === (int)$second['createdAt']) {
			$this->assertGreaterThan((int)$second['id'], (int)$first['id'], 'id DESC tie-break');
		}
		$this->assertSame('second', $first['reason']);
	}
}
