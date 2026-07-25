<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Fixtures;

use PHPUnit\Framework\TestCase;

/** Guards N4 seedable fixture presence and CLI contract (SPEC §12 N4). */
final class ReferenceDatasetSeedScriptTest extends TestCase
{
	public function testSeedScriptExistsAndDocumentsN4Defaults(): void
	{
		$path = dirname(__DIR__, 2) . '/fixtures/seed-reference-dataset.php';
		self::assertFileExists($path);
		$src = (string)file_get_contents($path);
		self::assertStringContainsString('--locations=50', $src);
		self::assertStringContainsString('--items=2000', $src);
		self::assertStringContainsString('--movements=20000', $src);
		self::assertStringContainsString('PHP_SAPI', $src);
		self::assertStringContainsString('MovementService', $src);
		self::assertStringContainsString('InsufficientStockException', $src);
	}
}
