<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * AC-22 automated proxy: Check-family visual contract vs MobilityCheck.
 * Full screenshot UAT remains manual; this fails the build on class-prefix
 * leakage and missing shared shell tokens.
 */
final class FamilyVisualParityContractTest extends TestCase
{
	private string $ivRoot;
	private string $mcCss;

	protected function setUp(): void
	{
		parent::setUp();
		$this->ivRoot = dirname(__DIR__, 3);
		$mc = dirname($this->ivRoot) . '/mobilitycheck/css/app.css';
		if (!is_file($mc)) {
			$this->markTestSkipped('MobilityCheck CSS not present for parity contract');
		}
		$this->mcCss = (string)file_get_contents($mc);
	}

	public function testNoForeignFamilyClassPrefixesLeak(): void
	{
		$files = [
			$this->ivRoot . '/css/app.css',
			$this->ivRoot . '/js/app.js',
			$this->ivRoot . '/templates/common/page-start.php',
			$this->ivRoot . '/templates/common/navigation.php',
			$this->ivRoot . '/templates/dashboard.php',
			$this->ivRoot . '/templates/settings.php',
			$this->ivRoot . '/templates/items.php',
			$this->ivRoot . '/templates/item-detail.php',
			$this->ivRoot . '/templates/movements.php',
			$this->ivRoot . '/templates/locations.php',
			$this->ivRoot . '/templates/label-print.php',
		];
		foreach ($files as $path) {
			$this->assertFileExists($path);
			$src = (string)file_get_contents($path);
			$this->assertDoesNotMatchRegularExpression('/\bmc-/', $src, basename($path) . ' leaked mc-');
			$this->assertDoesNotMatchRegularExpression('/\bmn-/', $src, basename($path) . ' leaked mn-');
			$this->assertDoesNotMatchRegularExpression('/\bdkc-/', $src, basename($path) . ' leaked dkc-');
		}
	}

	public function testIvCssUsesFamilyShellTokensPresentInMobilityCheck(): void
	{
		$ivCss = (string)file_get_contents($this->ivRoot . '/css/app.css')
			. (string)file_get_contents($this->ivRoot . '/css/common/accessibility.css');
		foreach (['skip-link', 'page-header', 'empty-state', 'focus-visible', '44px'] as $token) {
			$this->assertStringContainsString($token, $ivCss, 'InventoryCheck missing family token: ' . $token);
			$this->assertStringContainsString($token, $this->mcCss, 'MobilityCheck unexpectedly missing: ' . $token);
		}
		$this->assertMatchesRegularExpression('/\.iv-/', $ivCss);
		$this->assertMatchesRegularExpression('/\.mc-/', $this->mcCss);
	}

	public function testResponsiveBreakpointMatchesFamilyContract(): void
	{
		$ivCss = (string)file_get_contents($this->ivRoot . '/css/app.css');
		// InventoryCheck SPEC §11.1: tables→cards below 720 px.
		$this->assertMatchesRegularExpression('/@media\s*\(\s*max-width:\s*720px\s*\)/', $ivCss);
		// MobilityCheck family uses tokens breakpoint (767) or explicit max-width.
		$this->assertTrue(
			(bool)preg_match('/@media\s*\(\s*max-width:\s*7\d{2}px\s*\)/', $this->mcCss)
			|| str_contains($this->mcCss, 'breakpoint'),
			'MobilityCheck must declare a responsive max-width / breakpoint',
		);
	}
}
