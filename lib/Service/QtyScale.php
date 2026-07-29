<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Exception\ValidationException;
use OCP\IConfig;

/**
 * Wave C1: optional fixed-scale-3 fractional quantities.
 *
 * Storage is always integer. When scale=0, storage == display (whole units).
 * When scale=3, storage is milli-units (display × 1000). Enable is one-way.
 */
final class QtyScale
{
	public const KEY = 'qty_scale';
	public const SCALE_INT = 0;
	public const SCALE_MILLI = 3;
	public const FACTOR = 1000;
	/** Max display quantity (whole or fractional) accepted from clients. */
	public const MAX_DISPLAY = 1_000_000;

	public static function current(IConfig $config): int
	{
		$raw = $config->getAppValue(Application::APP_ID, self::KEY, '0');
		return $raw === '3' ? self::SCALE_MILLI : self::SCALE_INT;
	}

	public static function isFractional(IConfig $config): bool
	{
		return self::current($config) === self::SCALE_MILLI;
	}

	public static function factor(IConfig $config): int
	{
		return self::isFractional($config) ? self::FACTOR : 1;
	}

	/**
	 * One physical/serial unit in storage ints (1 when scale=0, 1000 when scale=3).
	 */
	public static function serialUnit(IConfig $config): int
	{
		return self::factor($config);
	}

	/** Absolute max storage int (= MAX_DISPLAY × factor). */
	public static function maxStorage(IConfig $config): int
	{
		return self::MAX_DISPLAY * self::factor($config);
	}

	/**
	 * Parse a client-facing qty into storage int.
	 */
	public static function toStorage(IConfig $config, mixed $display): int
	{
		if (self::current($config) === self::SCALE_INT) {
			if (is_int($display)) {
				return $display;
			}
			if (is_float($display) && is_finite($display) && floor($display) === $display) {
				return (int)$display;
			}
			$raw = trim((string)$display);
			if ($raw === '' || preg_match('/^-?\d+$/', $raw) !== 1) {
				throw new ValidationException('invalid_qty', '', [['field' => 'qty', 'code' => 'invalid_qty']]);
			}
			return (int)$raw;
		}

		$raw = trim((string)$display);
		if ($raw === '' || preg_match('/^-?\d+(\.\d{1,3})?$/', $raw) !== 1) {
			throw new ValidationException('invalid_qty', '', [['field' => 'qty', 'code' => 'invalid_qty']]);
		}
		$negative = str_starts_with($raw, '-');
		if ($negative) {
			$raw = substr($raw, 1);
		}
		[$whole, $frac] = array_pad(explode('.', $raw, 2), 2, '');
		$frac = str_pad($frac, 3, '0');
		$milli = ((int)$whole) * self::FACTOR + (int)$frac;
		return $negative ? -$milli : $milli;
	}

	/**
	 * Convert storage int to client-facing display (int or decimal string).
	 */
	public static function toDisplay(IConfig $config, int $storage): int|string
	{
		if (self::current($config) === self::SCALE_INT) {
			return $storage;
		}
		$neg = $storage < 0;
		$abs = abs($storage);
		$whole = intdiv($abs, self::FACTOR);
		$frac = $abs % self::FACTOR;
		$out = $frac === 0
			? (string)$whole
			: rtrim(rtrim(sprintf('%d.%03d', $whole, $frac), '0'), '.');
		return $neg ? '-' . $out : $out;
	}

	/** @param array<string, mixed> $item */
	public static function formatItem(array $item, IConfig $config): array
	{
		if (array_key_exists('reorderLevel', $item) && is_int($item['reorderLevel'])) {
			$item['reorderLevel'] = self::toDisplay($config, $item['reorderLevel']);
		}
		return $item;
	}

	/** @param array<string, mixed> $balance */
	public static function formatBalance(array $balance, IConfig $config): array
	{
		if (array_key_exists('qty', $balance) && is_int($balance['qty'])) {
			$balance['qty'] = self::toDisplay($config, $balance['qty']);
		}
		return $balance;
	}

	/** @param array<string, mixed> $movement */
	public static function formatMovement(array $movement, IConfig $config): array
	{
		foreach (['qtyDelta', 'qtyAfter'] as $key) {
			if (array_key_exists($key, $movement) && is_int($movement[$key])) {
				$movement[$key] = self::toDisplay($config, $movement[$key]);
			}
		}
		return $movement;
	}

	/** @param array<string, mixed> $row */
	public static function formatLowStock(array $row, IConfig $config): array
	{
		foreach (['totalQty', 'qty', 'reorderLevel'] as $key) {
			if (array_key_exists($key, $row) && is_int($row[$key])) {
				$row[$key] = self::toDisplay($config, $row[$key]);
			}
		}
		return $row;
	}

	/** @param array<string, mixed> $line */
	public static function formatCycleLine(array $line, IConfig $config): array
	{
		foreach (['systemQty', 'qtyCounted', 'currentQty'] as $key) {
			if (array_key_exists($key, $line) && is_int($line[$key])) {
				$line[$key] = self::toDisplay($config, $line[$key]);
			}
		}
		return $line;
	}
}
