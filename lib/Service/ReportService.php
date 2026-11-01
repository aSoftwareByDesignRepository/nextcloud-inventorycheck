<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Db\ItemMapper;

/**
 * F-12 reporting pack — read-only estimates, never a ledger (§13 D-2).
 * Integer minor-unit math only; multiply first, single divide, half-up.
 */
class ReportService
{
	public function __construct(
		private readonly ItemMapper $items,
		private readonly BalanceMapper $balances,
	) {
	}

	/**
	 * Estimated on-hand value: per item sum(qty) × unit price ÷ qtyFactor.
	 * Unpriced items are excluded from totals and counted — never silently 0.
	 *
	 * @return array{isEstimate: true, rows: list<array<string, mixed>>, totalMinor: int, unpricedCount: int}
	 */
	public function valuation(int $qtyFactor): array
	{
		$factor = $qtyFactor > 0 ? $qtyFactor : 1;
		$sums = $this->balances->sumQtyByItem(null);
		$rows = [];
		$total = 0;
		$unpriced = 0;
		foreach ($this->items->iterateActive() as $item) {
			$qty = $sums[(int)$item->getId()] ?? 0;
			$price = $item->getLastPriceMinor();
			$value = null;
			if ($price !== null) {
				$value = intdiv($qty * $price + intdiv($factor, 2), $factor);
				$total += $value;
			} else {
				$unpriced++;
			}
			$rows[] = [
				'itemId' => (int)$item->getId(),
				'sku' => $item->getSku(),
				'name' => $item->getName(),
				'category' => $item->getCategory(),
				'qty' => $qty,
				'unitPriceMinor' => $price,
				'valueMinor' => $value,
			];
		}
		return [
			'isEstimate' => true,
			'rows' => $rows,
			'totalMinor' => $total,
			'unpricedCount' => $unpriced,
		];
	}
}
