<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

/**
 * Restock F-01: pack-aware estimated cost math (integer minor units only —
 * NN-04: no float, no display strings; multiply first, single divide,
 * round half-up).
 */
final class RestockEstimate
{
	/**
	 * Orderable packs needed to cover $suggestedQty when the item is bought
	 * in packs of $packQty. 0 suggested → 0 packs; no pack → null.
	 */
	public static function packs(int $suggestedQty, ?int $packQty): ?int
	{
		if ($packQty === null || $packQty <= 0) {
			return null;
		}
		if ($suggestedQty <= 0) {
			return 0;
		}
		return intdiv($suggestedQty + $packQty - 1, $packQty);
	}

	/**
	 * Estimated line cost in minor units.
	 *  - pack fields set   → packs × packPrice
	 *  - unit fallback     → round_half_up(suggested × unitPrice / qtyFactor)
	 *  - nothing priced    → null (unpriced line — never silently 0, NN-04)
	 */
	public static function lineMinor(
		int $suggestedQty,
		?int $packQty,
		?int $packPriceMinor,
		?int $unitPriceMinor,
		int $qtyFactor,
	): ?int {
		if ($packQty !== null && $packQty > 0 && $packPriceMinor !== null) {
			$packs = self::packs($suggestedQty, $packQty);
			return ($packs ?? 0) * $packPriceMinor;
		}
		if ($unitPriceMinor === null || $suggestedQty <= 0) {
			return null;
		}
		$factor = $qtyFactor > 0 ? $qtyFactor : 1;
		return intdiv($suggestedQty * $unitPriceMinor + intdiv($factor, 2), $factor);
	}
}
