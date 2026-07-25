<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Config;

/**
 * Embedded vendor Ed25519 public key for IV2 verification.
 * Same key family as AZC2/PC2/MN2 (SbdLicenseOps).
 *
 * Optional override: IV_VENDOR_PUBLIC_KEY_B64 (PHPUnit fixture key only).
 */
final class VendorPublicKey
{
	public const DEFAULT_PUBLIC_KEY_B64 = 'naLgi4THUgwJCRoUehq20QU4uJsLVHzuKV04NhkITn8';

	/**
	 * Deterministic key for PHPUnit fixtures (license_iv2.json).
	 * Seed: sha256("inventorycheck-iv2-test-signing-v1").
	 */
	public const TEST_PUBLIC_KEY_B64 = 'dXRQySeXngzX5zMmsiPTU3fMmtufkUs-Jg9ZL-f2Bc0';

	public static function publicKeyB64(): string
	{
		$fromEnv = getenv('IV_VENDOR_PUBLIC_KEY_B64');
		if (is_string($fromEnv) && trim($fromEnv) !== '') {
			return trim($fromEnv);
		}
		return self::DEFAULT_PUBLIC_KEY_B64;
	}

	public static function bytes(): string
	{
		$decoded = self::base64urlDecode(self::publicKeyB64());
		if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
			throw new \RuntimeException('Invalid vendor public key configuration.');
		}
		return $decoded;
	}

	public static function base64urlDecode(string $data): string|false
	{
		$padded = strtr($data, '-_', '+/');
		$padLen = (4 - strlen($padded) % 4) % 4;
		return base64_decode($padded . str_repeat('=', $padLen), true);
	}

	public static function base64urlEncode(string $data): string
	{
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}
}
