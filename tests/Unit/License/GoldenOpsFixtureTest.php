<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\License;

use OCA\InventoryCheck\Config\VendorPublicKey;
use OCA\InventoryCheck\License\Iv2Codec;
use PHPUnit\Framework\TestCase;

/**
 * Phase 0.3 / G2-2 — ops IV2 golden must verify on the real consumer codec.
 */
final class GoldenOpsFixtureTest extends TestCase
{
	public static function setUpBeforeClass(): void
	{
		if (!defined('PHPUNIT_RUNNING')) {
			define('PHPUNIT_RUNNING', true);
		}
	}

	public function testOpsGoldenWireVerifies(): void
	{
		$opsFixture = dirname(__DIR__, 4) . '/sbdlicenseops/tests/fixtures/license_iv2_golden.json';
		self::assertFileExists($opsFixture);
		$data = json_decode((string)file_get_contents($opsFixture), true, 512, JSON_THROW_ON_ERROR);
		putenv('IV_VENDOR_PUBLIC_KEY_B64=' . (string)$data['publicKeyB64']);
		putenv('IV_ALLOW_VENDOR_KEY_OVERRIDE=1');
		try {
			self::assertSame('', Iv2Codec::classifyError((string)$data['wireKey']));
			$parsed = Iv2Codec::parseAndVerify((string)$data['wireKey']);
			self::assertNotNull($parsed);
			self::assertSame($data['payload'], $parsed['payload']);
			self::assertSame($data['payloadB64'], $parsed['payloadB64']);
			self::assertSame($data['signatureB64'], $parsed['signatureB64']);
		} finally {
			putenv('IV_VENDOR_PUBLIC_KEY_B64=' . VendorPublicKey::TEST_PUBLIC_KEY_B64);
			putenv('IV_ALLOW_VENDOR_KEY_OVERRIDE=1');
		}
	}

	public function testForeignAzc2Rejected(): void
	{
		$opsFixture = dirname(__DIR__, 4) . '/sbdlicenseops/tests/fixtures/license_azc2_golden.json';
		$data = json_decode((string)file_get_contents($opsFixture), true, 512, JSON_THROW_ON_ERROR);
		putenv('IV_VENDOR_PUBLIC_KEY_B64=' . (string)$data['publicKeyB64']);
		putenv('IV_ALLOW_VENDOR_KEY_OVERRIDE=1');
		try {
			$code = Iv2Codec::classifyError((string)$data['wireKey']);
			self::assertSame(Iv2Codec::ERROR_INVALID_FORMAT, $code);
		} finally {
			putenv('IV_VENDOR_PUBLIC_KEY_B64=' . VendorPublicKey::TEST_PUBLIC_KEY_B64);
			putenv('IV_ALLOW_VENDOR_KEY_OVERRIDE=1');
		}
	}
}
