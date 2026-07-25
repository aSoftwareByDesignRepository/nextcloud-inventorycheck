<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Db;

use PHPUnit\Framework\TestCase;

/**
 * AC-18 / DB standards — null clears must use PARAM_NULL (MariaDB-safe).
 */
final class ClaimPairingNullProtocolTest extends TestCase
{
	public function testClaimPairingUsesParamNullForClearedColumns(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Db/ScanDeviceMapper.php');
		self::assertStringContainsString('function claimPairing', $src);
		self::assertStringContainsString('IQueryBuilder::PARAM_NULL', $src);
		self::assertStringNotContainsString("createNamedParameter(null)", $src);
		self::assertGreaterThanOrEqual(2, substr_count($src, 'PARAM_NULL'));
	}
}
