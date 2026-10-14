<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service\Lookup;

use OCA\InventoryCheck\Service\Lookup\Gtin;
use PHPUnit\Framework\TestCase;

final class GtinTest extends TestCase
{
	public function testValidFormats(): void
	{
		self::assertTrue(Gtin::isValidFormat('73513537'));
		self::assertTrue(Gtin::isValidFormat('036000291452'));
		self::assertTrue(Gtin::isValidFormat('4006381333931'));
		self::assertTrue(Gtin::isValidFormat('00614141999996'));
	}

	public function testInvalidLengthsAndCharacters(): void
	{
		foreach (['', '1234567', '123456789', '12345678901', '123456789012345',
			'abcdefghijklm', '40063813339 1', ' 4006381333931', "4006381333931\n"] as $code) {
			self::assertFalse(Gtin::isValidFormat($code), $code);
		}
	}

	public function testCheckDigitAcceptedForRealBarcodes(): void
	{
		// All four GTIN families use the identical GS1 mod-10 rule.
		self::assertTrue(Gtin::hasValidCheckDigit('73513537'));
		self::assertTrue(Gtin::hasValidCheckDigit('036000291452'));
		self::assertTrue(Gtin::hasValidCheckDigit('4006381333931'));
		self::assertTrue(Gtin::hasValidCheckDigit('00614141999996'));
	}

	public function testCheckDigitRejectsTypo(): void
	{
		self::assertFalse(Gtin::hasValidCheckDigit('4006381333932'));
		self::assertFalse(Gtin::hasValidCheckDigit('73513538'));
	}

	public function testCheckDigitRejectsMalformedInput(): void
	{
		self::assertFalse(Gtin::hasValidCheckDigit(''));
		self::assertFalse(Gtin::hasValidCheckDigit('12345'));
	}

	public function testIsValidRequiresFormatAndCheckDigit(): void
	{
		self::assertTrue(Gtin::isValid('4006381333931'));
		self::assertFalse(Gtin::isValid('4006381333932'));
		self::assertFalse(Gtin::isValid('12345'));
	}
}
