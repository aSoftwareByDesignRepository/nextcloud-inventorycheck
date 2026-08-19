<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class MobileSeatSchemaContractTest extends TestCase
{
	public function testRemoveSeatFailsClosedWhenSeatTableMissing(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/LicenseService.php');
		$start = strpos($src, 'function removeSeat');
		$this->assertNotFalse($start);
		$fn = substr($src, $start, 700);
		$this->assertStringContainsString('tableExists(MobileSeatMapper::TABLE)', $fn);
		$this->assertStringContainsString('return;', $fn);
		$this->assertStringContainsString('findByUid($uid)', $fn);
		$this->assertStringNotContainsString('function status', $fn);
	}

	public function testLateMigrationCreatesMissingSeatTable(): void
	{
		$src = (string)file_get_contents(
			dirname(__DIR__, 2) . '/lib/Migration/Version1006Date20260818224500.php'
		);
		$this->assertStringContainsString("hasTable('iv_mobile_seats')", $src);
		$this->assertStringContainsString("createTable('iv_mobile_seats')", $src);
		$this->assertStringContainsString("'iv_seat_pk'", $src);
		$this->assertStringContainsString("'iv_seat_uid_uq'", $src);
	}
}
