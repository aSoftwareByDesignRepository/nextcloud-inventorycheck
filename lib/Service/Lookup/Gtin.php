<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service\Lookup;

/**
 * GTIN/EAN/UPC validation: format (8|12|13|14 digits) plus the GS1 check digit.
 *
 * Check digit: from the rightmost data digit, weights alternate 3,1,3,1…
 * check = (10 - (sum mod 10)) mod 10 — identical rule for all four lengths.
 */
final class Gtin
{
	public const PATTERN = '/^(?:\d{8}|\d{12}|\d{13}|\d{14})$/D';

	public static function isValidFormat(string $code): bool
	{
		return preg_match(self::PATTERN, $code) === 1;
	}

	public static function hasValidCheckDigit(string $code): bool
	{
		if (!self::isValidFormat($code)) {
			return false;
		}
		$len = strlen($code);
		$sum = 0;
		for ($i = 0; $i < $len - 1; $i++) {
			$digit = ord($code[$len - 2 - $i]) - 48;
			$sum += $digit * ($i % 2 === 0 ? 3 : 1);
		}
		$expected = (10 - ($sum % 10)) % 10;
		return (ord($code[$len - 1]) - 48) === $expected;
	}

	/** Format + check digit — the bar a code must clear before any upstream call. */
	public static function isValid(string $code): bool
	{
		return self::isValidFormat($code) && self::hasValidCheckDigit($code);
	}
}
