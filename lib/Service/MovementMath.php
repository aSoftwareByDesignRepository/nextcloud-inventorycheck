<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

/**
 * Pure movement quantity math (SPEC S1, §5.1–5.3).
 *
 * Bounds:
 *   per-movement qty: 1 ≤ qty ≤ 1_000_000
 *   resulting balance: −2_000_000_000 ≤ qty_after ≤ 2_000_000_000
 */
final class MovementMath
{
	public const QTY_MIN = 1;
	public const QTY_MAX = 1_000_000;
	public const BALANCE_MIN = -2_000_000_000;
	public const BALANCE_MAX = 2_000_000_000;

	public static function isValidMovementQty(int $qty): bool
	{
		return $qty >= self::QTY_MIN && $qty <= self::QTY_MAX;
	}

	public static function isValidBalance(int $qtyAfter): bool
	{
		return $qtyAfter >= self::BALANCE_MIN && $qtyAfter <= self::BALANCE_MAX;
	}

	/**
	 * Signed delta for a single-leg kind given a positive movement qty.
	 * adjust is not handled here — see AdjustSemantics.
	 */
	public static function deltaForKind(string $kind, int $qty): int
	{
		return match ($kind) {
			'receive', 'transfer_in' => $qty,
			'issue', 'transfer_out' => -$qty,
			default => throw new \InvalidArgumentException('unknown_kind'),
		};
	}

	public static function applyDelta(int $currentQty, int $delta): int
	{
		return $currentQty + $delta;
	}

	/**
	 * SPEC §5.2 / S4 insufficient-stock rule.
	 *
	 * Blocked iff qty_after < 0 AND negative stock is not permitted for this
	 * operation. When allowNegative is false, a movement that would make an
	 * already-negative balance *more* negative is blocked; receives and
	 * adjusts toward ≥ 0 are always allowed.
	 */
	public static function isInsufficientStock(
		int $qtyBefore,
		int $qtyAfter,
		bool $allowNegative,
	): bool {
		if ($allowNegative) {
			return false;
		}
		if ($qtyAfter >= 0) {
			return false;
		}
		// More negative (or newly negative): blocked.
		return $qtyAfter < $qtyBefore;
	}
}
