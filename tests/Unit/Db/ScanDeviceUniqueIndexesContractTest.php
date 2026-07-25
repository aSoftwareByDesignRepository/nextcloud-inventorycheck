<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Db;

use PHPUnit\Framework\TestCase;

/** N5 / AC-18 — token and pair-code hashes must be unique at the schema layer. */
final class ScanDeviceUniqueIndexesContractTest extends TestCase
{
	public function testMigrationAddsUniqueIndexes(): void
	{
		$src = (string)file_get_contents(
			dirname(__DIR__, 3) . '/lib/Migration/Version1001Date20260724210000.php',
		);
		self::assertStringContainsString("addUniqueIndex(['token_hash'], 'iv_dev_tok_uq')", $src);
		self::assertStringContainsString("addUniqueIndex(['pair_code_hash'], 'iv_dev_pair_uq')", $src);
	}

	public function testClaimRequiresNullTokenHash(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Db/ScanDeviceMapper.php');
		self::assertStringContainsString("isNull('token_hash')", $src);
		self::assertStringContainsString('function rotatePairCode', $src);
	}
}
