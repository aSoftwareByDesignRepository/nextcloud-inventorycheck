<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Service\SuggestedOrder;
use PHPUnit\Framework\TestCase;

final class SuggestedOrderTest extends TestCase
{
	public function testUsesTargetWhenSet(): void
	{
		$this->assertSame(7, SuggestedOrder::qty(3, 5, 10));
	}

	public function testFallsBackToReorderDeficit(): void
	{
		$this->assertSame(2, SuggestedOrder::qty(3, 5, null));
		$this->assertSame(0, SuggestedOrder::qty(5, 5, null));
	}

	public function testNeverNegative(): void
	{
		$this->assertSame(0, SuggestedOrder::qty(20, 5, 10));
	}

	public function testZeroReorderWithoutTarget(): void
	{
		$this->assertSame(0, SuggestedOrder::qty(0, 0, null));
	}
}
