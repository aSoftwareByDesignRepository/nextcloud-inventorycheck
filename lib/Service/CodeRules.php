<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

/**
 * SKU / scan_code / location code rules (SPEC S7, S19).
 *
 * Charset ^[A-Za-z0-9._/-]{1,N}$ — no spaces (wedge scanners / QR).
 * Stored as entered after trim; uniqueness is exact byte match (case-sensitive).
 */
final class CodeRules
{
	public const SKU_MAX = 64;
	public const SCAN_MAX = 128;
	public const LOC_CODE_MAX = 64;

	/**
	 * Shared exclusive lock for item sku/scan_code and location.code mutations
	 * so item↔location cross-uniqueness cannot race (EXEC A7).
	 */
	public const CODES_LOCK = 'inventorycheck/item_codes';

	public const PATTERN_SKU = '/^[A-Za-z0-9._\\/-]{1,64}$/';
	public const PATTERN_SCAN = '/^[A-Za-z0-9._\\/-]{1,128}$/';
	public const PATTERN_LOC = '/^[A-Za-z0-9._\\/-]{1,64}$/';
	public const PATTERN_LOT = '/^[A-Za-z0-9._\\/-]{1,64}$/';

	/** @var list<string> Wave C2 item tracking modes. */
	public const TRACK_MODES = ['none', 'lot', 'serial'];

	public static function trim(string $value): string
	{
		return trim($value);
	}

	public static function isValidSku(string $value): bool
	{
		return $value !== '' && preg_match(self::PATTERN_SKU, $value) === 1;
	}

	public static function isValidScanCode(string $value): bool
	{
		return $value !== '' && preg_match(self::PATTERN_SCAN, $value) === 1;
	}

	public static function isValidLocationCode(string $value): bool
	{
		return $value !== '' && preg_match(self::PATTERN_LOC, $value) === 1;
	}

	/** Wave C2: lot/serial code — same charset as SKU/location codes, 1–64 chars. */
	public static function isValidLotCode(string $value): bool
	{
		return $value !== '' && preg_match(self::PATTERN_LOT, $value) === 1;
	}

	public static function isValidTrackMode(string $value): bool
	{
		return in_array($value, self::TRACK_MODES, true);
	}

	/**
	 * Cross-field uniqueness: a scan_code must not equal another item's sku
	 * and vice versa (S7). Same-item sku === scan_code is allowed (D6 default).
	 *
	 * @param list<array{id: int, sku: string, scanCode: string}> $others
	 */
	public static function conflictsWithOthers(
		?int $selfId,
		string $sku,
		string $scanCode,
		array $others,
	): bool {
		foreach ($others as $row) {
			if ($selfId !== null && (int)$row['id'] === $selfId) {
				continue;
			}
			$otherSku = (string)$row['sku'];
			$otherScan = (string)$row['scanCode'];
			if ($sku === $otherSku || $sku === $otherScan) {
				return true;
			}
			if ($scanCode === $otherSku || $scanCode === $otherScan) {
				return true;
			}
		}
		return false;
	}

	/**
	 * EXEC A7: item sku/scan_code must not equal any location.code (exact).
	 *
	 * @param list<string> $locationCodes
	 */
	public static function conflictsWithLocationCodes(
		string $sku,
		string $scanCode,
		array $locationCodes,
	): bool {
		foreach ($locationCodes as $code) {
			$code = (string)$code;
			if ($sku === $code || $scanCode === $code) {
				return true;
			}
		}
		return false;
	}

	/**
	 * EXEC A7: location.code must not equal any item sku or scan_code (exact).
	 *
	 * @param list<array{id: int, sku: string, scanCode: string}> $items
	 */
	public static function locationConflictsWithItems(string $code, array $items): bool
	{
		foreach ($items as $row) {
			if ($code === (string)$row['sku'] || $code === (string)$row['scanCode']) {
				return true;
			}
		}
		return false;
	}

	/** @return list<string> */
	public static function locationKinds(): array
	{
		return ['warehouse', 'shelf', 'van', 'site', 'other'];
	}

	public static function isValidLocationKind(string $kind): bool
	{
		return in_array($kind, self::locationKinds(), true);
	}
}
