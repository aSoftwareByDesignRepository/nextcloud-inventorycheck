<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\License;

use OCA\InventoryCheck\Config\VendorPublicKey;
use OCA\InventoryCheck\License\Iv2Codec;
use OCA\InventoryCheck\Tests\Support\Iv2TestSigning;
use PHPUnit\Framework\TestCase;

final class Iv2CodecTest extends TestCase
{
	/** @var string|false */
	private $prevEnv;

	protected function setUp(): void
	{
		$this->prevEnv = getenv('IV_VENDOR_PUBLIC_KEY_B64');
		putenv('IV_VENDOR_PUBLIC_KEY_B64=' . Iv2TestSigning::publicKeyB64());
		$this->assertSame(VendorPublicKey::TEST_PUBLIC_KEY_B64, Iv2TestSigning::publicKeyB64());
	}

	protected function tearDown(): void
	{
		if ($this->prevEnv === false) {
			putenv('IV_VENDOR_PUBLIC_KEY_B64');
		} else {
			putenv('IV_VENDOR_PUBLIC_KEY_B64=' . $this->prevEnv);
		}
	}

	/** @return array<string, mixed> */
	private function validPayload(array $overrides = []): array
	{
		return array_merge([
			'v' => 2,
			'product' => 'inventorycheck',
			'customerId' => 'acme-gmbh',
			'issuedAt' => '2026-07-24',
			'validUntil' => '2027-07-24',
			'mobileSeats' => 5,
			'scanDevices' => 2,
		], $overrides);
	}

	public function testValidRoundTrip(): void
	{
		$wire = Iv2TestSigning::signPayload($this->validPayload());
		$this->assertSame('', Iv2Codec::classifyError($wire));
		$parsed = Iv2Codec::parseAndVerify($wire);
		$this->assertNotNull($parsed);
		$this->assertSame('acme-gmbh', $parsed['payload']['customerId']);
		$this->assertSame(5, $parsed['payload']['mobileSeats']);
		$this->assertSame(2, $parsed['payload']['scanDevices']);
	}

	public function testBundleCanonicalPresentOnlyWhenTrue(): void
	{
		$withBundle = Iv2TestSigning::signPayload($this->validPayload(['bundle' => true]));
		$this->assertSame('', Iv2Codec::classifyError($withBundle));

		$payload = $this->validPayload();
		$payload['bundle'] = false;
		$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$wire = Iv2TestSigning::signRawBytes($json);
		$this->assertSame(Iv2Codec::ERROR_INVALID_PAYLOAD, Iv2Codec::classifyError($wire));
	}

	public function testRejectsSeatsAndDevicesBothZero(): void
	{
		$wire = Iv2TestSigning::signPayload($this->validPayload([
			'mobileSeats' => 0,
			'scanDevices' => 0,
		]));
		$this->assertSame(Iv2Codec::ERROR_INVALID_PAYLOAD, Iv2Codec::classifyError($wire));
	}

	public function testAcceptsSeatsOnly(): void
	{
		$wire = Iv2TestSigning::signPayload($this->validPayload([
			'mobileSeats' => 3,
			'scanDevices' => 0,
		]));
		$this->assertSame('', Iv2Codec::classifyError($wire));
	}

	public function testWrongProduct(): void
	{
		$wire = Iv2TestSigning::signPayload($this->validPayload(['product' => 'maintenancecheck']));
		$this->assertSame(Iv2Codec::ERROR_INVALID_PAYLOAD, Iv2Codec::classifyError($wire));
	}

	public function testWrongVersion(): void
	{
		$wire = Iv2TestSigning::signPayload($this->validPayload(['v' => 1]));
		$this->assertSame(Iv2Codec::ERROR_INVALID_PAYLOAD, Iv2Codec::classifyError($wire));
	}

	public function testTamperedSignature(): void
	{
		$wire = Iv2TestSigning::signPayload($this->validPayload());
		$parts = explode('.', $wire);
		$parts[2] = strrev($parts[2]);
		$this->assertSame(Iv2Codec::ERROR_INVALID_SIGNATURE, Iv2Codec::classifyError(implode('.', $parts)));
	}

	public function testInvalidFormatPartCount(): void
	{
		$this->assertSame(Iv2Codec::ERROR_INVALID_FORMAT, Iv2Codec::classifyError('IV2.onlyone'));
		$this->assertSame(Iv2Codec::ERROR_INVALID_FORMAT, Iv2Codec::classifyError('MN2.a.b'));
	}

	public function testWhitespaceNormalized(): void
	{
		$wire = Iv2TestSigning::signPayload($this->validPayload());
		$spaced = substr($wire, 0, 10) . " \n" . substr($wire, 10);
		$this->assertSame('', Iv2Codec::classifyError($spaced));
	}

	public function testIsValidOnInclusive(): void
	{
		$this->assertTrue(Iv2Codec::isValidOn('2026-07-24', '2026-07-24'));
		$this->assertFalse(Iv2Codec::isValidOn('2026-07-23', '2026-07-24'));
	}

	public function testIssuedAfterValidUntilRejected(): void
	{
		$wire = Iv2TestSigning::signPayload($this->validPayload([
			'issuedAt' => '2027-01-01',
			'validUntil' => '2026-01-01',
		]));
		$this->assertSame(Iv2Codec::ERROR_INVALID_PAYLOAD, Iv2Codec::classifyError($wire));
	}

	public function testValidatePayloadRejectsBundleFalsePresent(): void
	{
		$payload = $this->validPayload(['bundle' => false]);
		$this->assertFalse(Iv2Codec::validatePayloadFields($payload));
	}

	public function testNonCanonicalKeyOrderRejectedEvenIfFieldsValid(): void
	{
		$payload = $this->validPayload();
		// Valid fields but wrong key order / extra whitespace style via re-encode unordered.
		$unordered = [
			'mobileSeats' => $payload['mobileSeats'],
			'scanDevices' => $payload['scanDevices'],
			'validUntil' => $payload['validUntil'],
			'issuedAt' => $payload['issuedAt'],
			'customerId' => $payload['customerId'],
			'product' => $payload['product'],
			'v' => $payload['v'],
		];
		$json = json_encode($unordered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$this->assertNotFalse($json);
		$wire = Iv2TestSigning::signRawBytes($json);
		$this->assertSame(Iv2Codec::ERROR_INVALID_PAYLOAD, Iv2Codec::classifyError($wire));
		$this->assertTrue(Iv2Codec::validatePayloadFields($unordered));
	}
}
