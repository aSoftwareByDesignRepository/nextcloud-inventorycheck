<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

/**
 * Wave D4: suggested reorder qty from on-hand vs target_stock or reorder_level.
 */
final class SuggestedOrder
{
	/**
	 * When target_stock is set (> 0), order up to target.
	 * Else order up to reorder_level (deficit to threshold).
	 * Never negative.
	 */
	public static function qty(int $onHand, int $reorderLevel, ?int $targetStock): int
	{
		if ($targetStock !== null && $targetStock > 0) {
			return max(0, $targetStock - $onHand);
		}
		if ($reorderLevel <= 0) {
			return 0;
		}
		return max(0, $reorderLevel - $onHand);
	}
}
