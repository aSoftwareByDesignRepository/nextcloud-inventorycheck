<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\RestockConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/** IV-LOT-EXPIRY-PACK: expiry window 0..365, 0 = off (AC-7/AC-14). */
final class RestockConfigExpiryTest extends TestCase
{
	private function config(string $stored = ''): RestockConfig
	{
		$cfg = $this->createMock(IConfig::class);
		$cfg->method('getAppValue')
			->with(Application::APP_ID, RestockConfig::KEY_EXPIRY_WARN_DAYS, '')
			->willReturn($stored);
		return new RestockConfig($cfg);
	}

	public function testUnsetDefaultsTo30(): void
	{
		self::assertSame(30, $this->config('')->expiryWarnDays());
	}

	public function testZeroMeansOff(): void
	{
		self::assertSame(0, $this->config('0')->expiryWarnDays());
	}

	public function testStoredValuePassesThrough(): void
	{
		self::assertSame(14, $this->config('14')->expiryWarnDays());
	}

	public function testOutOfRangeFallsBackToDefault(): void
	{
		self::assertSame(30, $this->config('366')->expiryWarnDays());
		self::assertSame(30, $this->config('-5')->expiryWarnDays());
	}

	public function testParseAcceptsZeroAndBounds(): void
	{
		$svc = $this->config();
		self::assertSame(0, $svc->parseExpiryWarnDays(0));
		self::assertSame(0, $svc->parseExpiryWarnDays('0'));
		self::assertSame(365, $svc->parseExpiryWarnDays(365));
		self::assertSame(1, $svc->parseExpiryWarnDays('1'));
	}

	public function testParseRejectsOutOfRangeAndNonNumeric(): void
	{
		$svc = $this->config();
		foreach ([366, -1, 'abc', '1.5', null, ['x']] as $bad) {
			try {
				$svc->parseExpiryWarnDays($bad);
				self::fail('expected ValidationException for ' . var_export($bad, true));
			} catch (ValidationException $e) {
				self::assertSame('validation_failed', $e->getErrorCode());
				self::assertSame('expiryWarnDays', $e->getDetails()[0]['field']);
			}
		}
	}
}
