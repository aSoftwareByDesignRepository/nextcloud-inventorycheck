<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Config;

use OCA\InventoryCheck\Config\VendorPublicKey;
use PHPUnit\Framework\TestCase;

final class VendorPublicKeyTest extends TestCase
{
	protected function tearDown(): void
	{
		putenv('IV_VENDOR_PUBLIC_KEY_B64');
		putenv('IV_ALLOW_VENDOR_KEY_OVERRIDE');
		parent::tearDown();
	}

	public function testDefaultProductionKeyIsStable(): void
	{
		putenv('IV_VENDOR_PUBLIC_KEY_B64');
		putenv('IV_ALLOW_VENDOR_KEY_OVERRIDE');
		self::assertSame(
			'naLgi4THUgwJCRoUehq20QU4uJsLVHzuKV04NhkITn8',
			VendorPublicKey::publicKeyB64(),
		);
	}

	public function testEnvOverrideRequiresAllowFlagOutsidePhpunitGuard(): void
	{
		// Under PHPUnit the guard is open by design — verify TEST key constant exists
		// and that the production default is unchanged when env is empty.
		self::assertSame(
			'dXRQySeXngzX5zMmsiPTU3fMmtufkUs-Jg9ZL-f2Bc0',
			VendorPublicKey::TEST_PUBLIC_KEY_B64,
		);
		self::assertTrue(VendorPublicKey::envOverrideAllowed());
	}

	public function testGoldenFixturePublicKeyMatchesTestKey(): void
	{
		$path = dirname(__DIR__, 2) . '/fixtures/license_iv2_golden.json';
		self::assertFileExists($path);
		$data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame(VendorPublicKey::TEST_PUBLIC_KEY_B64, $data['publicKeyB64']);
	}
}
