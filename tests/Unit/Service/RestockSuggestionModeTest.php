<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\RestockConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * F-10 suggestion mode (intake 20261102T011538Z): the mode must be a
 * writable, whitelisted two-state enum — previously scaffolded with a
 * setter nothing could call (occ-only), making velocityWindowDays dead
 * chrome. static|coverage is the stored vocabulary; anything else snaps
 * back to static.
 */
final class RestockSuggestionModeTest extends TestCase
{
	private function makeConfig(array &$store): IConfig
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$store): string {
				return $store[$app . '/' . $key] ?? $default;
			},
		);
		$config->method('setAppValue')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$store): void {
				$store[$app . '/' . $key] = $value;
			},
		);
		return $config;
	}

	public function testDefaultIsStatic(): void
	{
		$store = [];
		$cfg = new RestockConfig($this->makeConfig($store));
		self::assertSame('static', $cfg->suggestionMode());
	}

	public function testCoverageRoundTripsThroughSetter(): void
	{
		$store = [];
		$cfg = new RestockConfig($this->makeConfig($store));
		$cfg->setSuggestionMode('coverage');
		self::assertSame('coverage', $cfg->suggestionMode());
		self::assertSame(
			'coverage',
			$store[Application::APP_ID . '/' . RestockConfig::KEY_SUGGESTION_MODE],
		);
		$cfg->setSuggestionMode('static');
		self::assertSame('static', $cfg->suggestionMode());
	}

	public function testUnknownValueNeverPersists(): void
	{
		$store = [];
		$cfg = new RestockConfig($this->makeConfig($store));
		$cfg->setSuggestionMode('banana');
		self::assertSame('static', $cfg->suggestionMode());
		self::assertSame(
			'static',
			$store[Application::APP_ID . '/' . RestockConfig::KEY_SUGGESTION_MODE],
		);
	}

	public function testStoredGarbageReadsAsStatic(): void
	{
		$store = [Application::APP_ID . '/' . RestockConfig::KEY_SUGGESTION_MODE => 'weird'];
		$cfg = new RestockConfig($this->makeConfig($store));
		self::assertSame('static', $cfg->suggestionMode());
	}

	public function testWhitelistIsExactlyTwoModes(): void
	{
		self::assertSame(['static', 'coverage'], RestockConfig::SUGGESTION_MODES);
	}
}
