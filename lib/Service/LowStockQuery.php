<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

/**
 * Low-stock pure predicate (SPEC S10).
 *
 * Low stock = active item with reorder_level > 0 and SUM(qty) < reorder_level (strict).
 * reorder_level = 0 never appears; total == reorder_level is NOT low.
 */
final class LowStockQuery
{
	public static function isLowStock(bool $active, int $reorderLevel, int $totalQty): bool
	{
		if (!$active) {
			return false;
		}
		if ($reorderLevel <= 0) {
			return false;
		}
		return $totalQty < $reorderLevel;
	}

	/**
	 * Sort key: (total − reorder_level) ASC — most deficit first.
	 */
	public static function deficit(int $totalQty, int $reorderLevel): int
	{
		return $totalQty - $reorderLevel;
	}
}
