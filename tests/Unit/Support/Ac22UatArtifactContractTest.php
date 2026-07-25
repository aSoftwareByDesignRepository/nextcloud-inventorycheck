<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/** AC-22 artifact must exist with screenshots so Store UAT is not forgotten. */
final class Ac22UatArtifactContractTest extends TestCase
{
	public function testFamilyUatChecklistPresentAndChecked(): void
	{
		$path = dirname(__DIR__, 3) . '/docs/uat/AC-22-FAMILY-UAT.md';
		self::assertFileExists($path);
		$src = (string)file_get_contents($path);
		self::assertStringContainsString('MobilityCheck', $src);
		self::assertStringContainsString('docs/uat/screenshots/', $src);
		self::assertStringContainsString('Settings', $src);
		self::assertStringContainsString('[x]', $src, 'AC-22 criteria must be signed off');
		self::assertDoesNotMatchRegularExpression('/^- \[ \]/m', $src, 'no unchecked criteria remain');
		self::assertMatchesRegularExpression('/\|\s*UX\s*\|.+\|/', $src);
		self::assertMatchesRegularExpression('/\|\s*QA\s*\|.+\|/', $src);
	}

	public function testScreenshotArchiveHasRequiredSurfaces(): void
	{
		$dir = dirname(__DIR__, 3) . '/docs/uat/screenshots';
		self::assertDirectoryExists($dir);
		$pngs = glob($dir . '/*.png') ?: [];
		self::assertGreaterThanOrEqual(5, count($pngs), 'need archived PNG surfaces');
		$names = array_map('basename', $pngs);
		self::assertTrue(
			(bool)array_filter($names, static fn (string $n): bool => str_contains($n, 'dashboard')),
			'dashboard screenshot required',
		);
		self::assertTrue(
			(bool)array_filter($names, static fn (string $n): bool => str_contains($n, '320') || str_contains($n, 'live-')),
			'320px or live screenshot required',
		);
		self::assertTrue(
			(bool)array_filter($names, static fn (string $n): bool => str_contains($n, 'settings') || str_contains($n, 'license')),
			'settings/license screenshot required',
		);
		self::assertTrue(
			(bool)array_filter($names, static fn (string $n): bool => str_contains($n, 'movements') || str_contains($n, 'dialog')),
			'movements/dialog screenshot required',
		);
		self::assertTrue(
			(bool)array_filter($names, static fn (string $n): bool => str_contains($n, 'label') || str_contains($n, 'a12') || str_contains($n, 'live-')),
			'A12 label or live screenshot required',
		);
		foreach ($pngs as $png) {
			self::assertGreaterThan(1000, filesize($png), basename($png) . ' looks empty');
		}
	}
}
