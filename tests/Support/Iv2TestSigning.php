<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Support;

use OCA\InventoryCheck\Config\VendorPublicKey;
use OCA\InventoryCheck\License\Iv2Codec;

/**
 * Deterministic IV2 signing for tests.
 * Seed: sha256("inventorycheck-iv2-test-signing-v1").
 * NEVER use outside of tests.
 */
final class Iv2TestSigning
{
	public const SEED_STRING = 'inventorycheck-iv2-test-signing-v1';

	public static function secretKey(): string
	{
		$seed = hash('sha256', self::SEED_STRING, true);
		$keypair = sodium_crypto_sign_seed_keypair($seed);
		return sodium_crypto_sign_secretkey($keypair);
	}

	public static function publicKeyB64(): string
	{
		$seed = hash('sha256', self::SEED_STRING, true);
		$keypair = sodium_crypto_sign_seed_keypair($seed);
		return VendorPublicKey::base64urlEncode(sodium_crypto_sign_publickey($keypair));
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public static function signPayload(array $payload): string
	{
		return self::signRawBytes(Iv2Codec::canonicalJson($payload));
	}

	public static function signRawBytes(string $payloadBytes): string
	{
		$signature = sodium_crypto_sign_detached($payloadBytes, self::secretKey());
		return Iv2Codec::FORMAT . '.'
			. VendorPublicKey::base64urlEncode($payloadBytes) . '.'
			. VendorPublicKey::base64urlEncode($signature);
	}
}
