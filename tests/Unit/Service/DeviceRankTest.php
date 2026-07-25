<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Service\DeviceRank;
use PHPUnit\Framework\TestCase;

final class DeviceRankTest extends TestCase
{
	public function testRanksByPairedThenId(): void
	{
		$devices = [
			['id' => 9, 'pairedAt' => 50],
			['id' => 8, 'pairedAt' => 10],
			['id' => 7, 'pairedAt' => 10],
		];
		$this->assertSame([7 => 1, 8 => 2, 9 => 3], DeviceRank::ranks($devices));
		$this->assertTrue(DeviceRank::isWithinLimit($devices, 7, 1));
		$this->assertFalse(DeviceRank::isWithinLimit($devices, 8, 1));
		$this->assertFalse(DeviceRank::isWithinLimit($devices, 7, 0));
		$this->assertFalse(DeviceRank::isWithinLimit($devices, 99, 5));
	}
}
