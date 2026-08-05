<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Service\MovementService;
use PHPUnit\Framework\TestCase;

final class MovementListNamesTest extends TestCase
{
	public function testWithDisplayNamesAttachesExpectedKeys(): void
	{
		$base = [
			'id' => 1,
			'itemId' => 10,
			'locationId' => 20,
			'kind' => 'issue',
			'qtyDelta' => -2,
			'qtyAfter' => 8,
		];
		$row = MovementService::withDisplayNames(
			$base,
			'Air filter',
			'FILTER-42',
			'VAN-1',
			'Van front',
		);
		self::assertSame('Air filter', $row['itemName']);
		self::assertSame('FILTER-42', $row['sku']);
		self::assertSame('VAN-1', $row['locationCode']);
		self::assertSame('Van front', $row['locationName']);
		self::assertSame(10, $row['itemId']);
		self::assertSame(20, $row['locationId']);
	}

	public function testWithDisplayNamesNullableSafeWhenMastersDeleted(): void
	{
		$row = MovementService::withDisplayNames(
			['id' => 2, 'itemId' => 99, 'locationId' => 88],
			null,
			null,
			null,
			null,
		);
		self::assertArrayHasKey('itemName', $row);
		self::assertArrayHasKey('sku', $row);
		self::assertArrayHasKey('locationCode', $row);
		self::assertArrayHasKey('locationName', $row);
		self::assertNull($row['itemName']);
		self::assertNull($row['sku']);
		self::assertNull($row['locationCode']);
		self::assertNull($row['locationName']);
	}
}
