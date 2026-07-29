<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

/**
 * Pure cycle-count state guards (Wave B1) — mutation-friendly.
 */
final class CycleCountSemantics
{
	public static function canStart(string $status): bool
	{
		return $status === CycleCountService::STATUS_OPEN;
	}

	public static function canCountOrClose(string $status): bool
	{
		return $status === CycleCountService::STATUS_COUNTING;
	}

	public static function isIncomplete(?int $qtyCounted, bool $abandonUncounted): bool
	{
		return $qtyCounted === null && !$abandonUncounted;
	}

	public static function isQtyCountedValid(int $qtyCounted): bool
	{
		// Absolute storage ceiling covering scale=0 (≤1e6) and scale=3 (≤1e6×1000).
		return $qtyCounted >= 0 && $qtyCounted <= 1_000_000_000;
	}

	/**
	 * UC-C2: live balance moved after the campaign snapshot was frozen.
	 */
	public static function hasConflict(int $systemQty, int $currentQty): bool
	{
		return $systemQty !== $currentQty;
	}

	/**
	 * Close must not silently overwrite mid-count receives/issues unless office
	 * explicitly acknowledges the conflict (acknowledgeConflicts=true).
	 */
	public static function closeBlockedByConflicts(bool $hasAnyConflict, bool $acknowledgeConflicts): bool
	{
		return $hasAnyConflict && !$acknowledgeConflicts;
	}

	/**
	 * Inventur adjusts total qty without a lot/serial — only track_mode=none
	 * SKUs are eligible (create skips others; close must re-check).
	 */
	public static function isInventurEligible(string $trackMode): bool
	{
		return $trackMode === 'none';
	}
}
