<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Db\ItemMapper;

class LowStockService
{
	public function __construct(
		private readonly ItemMapper $items,
		private readonly BalanceMapper $balances,
	) {
	}

	/**
	 * S10: active items with reorder_level > 0 and SUM(qty) < reorder_level.
	 * Sorted by deficit ASC.
	 *
	 * @return array{data: list<array<string, mixed>>, total: int, limit: int, offset: int}
	 */
	public function list(int $limit, int $offset): array
	{
		$sums = $this->balances->sumQtyByItem();
		$all = $this->items->search('', true, 100000, 0);
		$rows = [];
		foreach ($all['data'] as $item) {
			$total = $sums[(int)$item->getId()] ?? 0;
			if (!LowStockQuery::isLowStock(true, $item->getReorderLevel(), $total)) {
				continue;
			}
			$rows[] = [
				'item' => $item->toApi(),
				'totalQty' => $total,
				'reorderLevel' => $item->getReorderLevel(),
				'deficit' => LowStockQuery::deficit($total, $item->getReorderLevel()),
			];
		}
		usort($rows, static function (array $a, array $b): int {
			if ($a['deficit'] !== $b['deficit']) {
				return $a['deficit'] <=> $b['deficit'];
			}
			return $a['item']['id'] <=> $b['item']['id'];
		});
		$total = count($rows);
		$data = array_slice($rows, $offset, $limit);
		return ['data' => $data, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
	}
}
