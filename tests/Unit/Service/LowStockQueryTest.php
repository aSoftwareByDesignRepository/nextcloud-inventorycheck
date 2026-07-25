<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Service\LowStockQuery;
use PHPUnit\Framework\TestCase;

final class LowStockQueryTest extends TestCase
{
	public function testStrictBoundary(): void
	{
		$this->assertTrue(LowStockQuery::isLowStock(true, 5, 4));
		$this->assertFalse(LowStockQuery::isLowStock(true, 5, 5));
		$this->assertFalse(LowStockQuery::isLowStock(true, 5, 6));
	}

	public function testReorderZeroNeverListed(): void
	{
		$this->assertFalse(LowStockQuery::isLowStock(true, 0, 0));
		$this->assertFalse(LowStockQuery::isLowStock(true, 0, -1));
	}

	public function testInactiveExcluded(): void
	{
		$this->assertFalse(LowStockQuery::isLowStock(false, 5, 1));
	}

	public function testDeficitSortKey(): void
	{
		$this->assertSame(-3, LowStockQuery::deficit(2, 5));
		$this->assertSame(0, LowStockQuery::deficit(5, 5));
	}
}
