<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Service\SeatRank;
use PHPUnit\Framework\TestCase;

final class SeatRankTest extends TestCase
{
	public function testRanksByAssignedThenId(): void
	{
		$seats = [
			['id' => 1, 'assignedAt' => 200],
			['id' => 3, 'assignedAt' => 100],
			['id' => 2, 'assignedAt' => 100],
		];
		// assigned_at ASC then id ASC → 2, 3, 1 (not raw id order 1,2,3)
		$this->assertSame([2 => 1, 3 => 2, 1 => 3], SeatRank::ranks($seats));
		$this->assertTrue(SeatRank::isWithinLimit($seats, 2, 2));
		$this->assertTrue(SeatRank::isWithinLimit($seats, 3, 2));
		$this->assertFalse(SeatRank::isWithinLimit($seats, 1, 2));
		$this->assertFalse(SeatRank::isWithinLimit($seats, 2, 0));
		$this->assertFalse(SeatRank::isWithinLimit($seats, 99, 5));
	}
}
