<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Repair;

use OCA\InventoryCheck\Repair\EnsureInventoryCheckSchema;
use PHPUnit\Framework\TestCase;

/**
 * Demo seed must run at most once so admins can delete WH / FILTER-42 without resurrection on repair.
 */
final class DemoSeedOnceContractTest extends TestCase
{
	public function testSeedDemoGatesOnAppConfigFlag(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Repair/EnsureInventoryCheckSchema.php');
		self::assertStringContainsString('DEMO_SEEDED_KEY', $src);
		self::assertSame('demo_seeded', EnsureInventoryCheckSchema::DEMO_SEEDED_KEY);
		self::assertMatchesRegularExpression(
			"/getAppValue\\([^,]+,\\s*self::DEMO_SEEDED_KEY,\\s*'0'\\)\\s*===\\s*'1'/",
			$src,
		);
		self::assertMatchesRegularExpression(
			"/setAppValue\\([^,]+,\\s*self::DEMO_SEEDED_KEY,\\s*'1'\\)/",
			$src,
		);
	}

	public function testUninstallClearsAllAppConfigIncludingDemoFlag(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Repair/UninstallDropTables.php');
		self::assertStringContainsString('deleteAppValues(self::APP_ID)', $src);
	}
}
