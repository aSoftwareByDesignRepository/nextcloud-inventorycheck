<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * AF-IV20 — session web Item/Movement controllers must never import or emit
 * mobile license 402 gate codes (IV2 seat/license misses stay mobile-only).
 */
final class WebNoLicense402ContractTest extends TestCase
{
	/** @return list<string> */
	private function webControllerSources(): array
	{
		$root = dirname(__DIR__, 3) . '/lib/Controller';
		return [
			(string)file_get_contents($root . '/ItemController.php'),
			(string)file_get_contents($root . '/MovementController.php'),
		];
	}

	public function testItemAndMovementControllersDoNotImportMobileGate(): void
	{
		foreach ($this->webControllerSources() as $src) {
			self::assertStringNotContainsString('MobileGateException', $src);
			self::assertStringNotContainsString('MobileGateService', $src);
			self::assertStringNotContainsString('license_missing', $src);
			self::assertStringNotContainsString('seat_required', $src);
			self::assertStringNotContainsString('seat_limit_exceeded', $src);
			self::assertStringNotContainsString('device_required', $src);
			self::assertStringNotContainsString('device_limit_exceeded', $src);
			self::assertStringNotContainsString('HTTP_PAYMENT_REQUIRED', $src);
			self::assertDoesNotMatchRegularExpression('/\b402\b/', $src);
		}
	}

	public function testMiddlewareMapsMobileGate402OnlyForMobileController(): void
	{
		$src = (string)file_get_contents(
			dirname(__DIR__, 3) . '/lib/Middleware/AppAccessMiddleware.php',
		);
		self::assertStringContainsString("str_contains(\$class, 'MobileController')", $src);
		self::assertMatchesRegularExpression(
			'/if \(\$exception instanceof MobileGateException\) \{\s*'
			. '\/\/ AF-IV20[\s\S]*?'
			. 'if \(!str_contains\(\$class, \'MobileController\'\)\) \{\s*'
			. 'throw \$exception;/',
			$src,
		);
		self::assertStringContainsString('self::HTTP_PAYMENT_REQUIRED', $src);
	}
}
