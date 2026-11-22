<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\LocationMapper;
use OCA\InventoryCheck\Db\MovementMapper;

/**
 * F-12 reporting pack — read-only estimates, never a ledger (§13 D-2).
 * Integer minor-unit math only; multiply first, single divide, half-up.
 */
class ReportService
{
	public function __construct(
		private readonly ItemMapper $items,
		private readonly BalanceMapper $balances,
		private readonly ?MovementMapper $movements = null,
		private readonly ?LocationMapper $locations = null,
		private readonly ?Clock $clock = null,
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

	/**
	 * F-12 movement-value report: spend + receive volume for a period.
	 * Plain SUM of spend_minor — no qty factor (NN-12).
	 *
	 * @return array{isEstimate: true, from: int, to: int, spendMinor: int, unpricedReceiveCount: int}
	 */
	public function movementValue(int $from, int $to): array
	{
		if ($this->movements === null) {
			return ['isEstimate' => true, 'from' => $from, 'to' => $to, 'spendMinor' => 0, 'unpricedReceiveCount' => 0];
		}
		$agg = $this->movements->spendInPeriod($from, $to);
		return [
			'isEstimate' => true,
			'from' => $from,
			'to' => $to,
			'spendMinor' => $agg['spendMinor'],
			'unpricedReceiveCount' => $agg['unpricedCount'],
		];
	}

	/**
	 * IV-REPORTS-SURFACE (MH-03): per-location estimated value —
	 * sumQtyByItemAndLocation × lastPriceMinor in one grouped aggregate;
	 * unpriced items are excluded from the value and counted (AC-4).
	 *
	 * @return array{isEstimate: true, rows: list<array{locationId:int, code:string, name:string, valueMinor:int, itemCount:int}>, totalMinor: int, unpricedCount: int}
	 */
	public function locationValue(int $qtyFactor): array
	{
		$factor = $qtyFactor > 0 ? $qtyFactor : 1;
		$matrix = $this->balances->sumQtyByItemAndLocation();
		$prices = [];
		foreach ($this->items->iterateActive() as $item) {
			$prices[(int)$item->getId()] = $item->getLastPriceMinor();
		}
		$names = [];
		if ($this->locations !== null) {
			foreach ($this->locations->search(null, 1000, 0)['data'] as $loc) {
				$names[(int)$loc->getId()] = ['code' => $loc->getCode(), 'name' => $loc->getName()];
			}
		}
		$perLoc = [];
		$itemsAtLoc = [];
		$unpricedItems = [];
		foreach ($matrix as $itemId => $byLoc) {
			$price = $prices[$itemId] ?? null;
			foreach ($byLoc as $locId => $qty) {
				if ($qty <= 0) {
					continue;
				}
				$itemsAtLoc[$locId] = ($itemsAtLoc[$locId] ?? 0) + 1;
				if ($price !== null) {
					$perLoc[$locId] = ($perLoc[$locId] ?? 0)
						+ intdiv($qty * $price + intdiv($factor, 2), $factor);
				} else {
					$unpricedItems[$itemId] = true;
				}
			}
		}
		$rows = [];
		$total = 0;
		foreach ($itemsAtLoc as $locId => $count) {
			$value = $perLoc[$locId] ?? 0;
			$total += $value;
			$meta = $names[$locId] ?? ['code' => '#' . $locId, 'name' => ''];
			$rows[] = [
				'locationId' => $locId,
				'code' => $meta['code'],
				'name' => $meta['name'],
				'valueMinor' => $value,
				'itemCount' => $count,
			];
		}
		usort($rows, static fn (array $a, array $b): int => $b['valueMinor'] <=> $a['valueMinor']);
		return [
			'isEstimate' => true,
			'rows' => $rows,
			'totalMinor' => $total,
			'unpricedCount' => count($unpricedItems),
		];
	}

	/**
	 * IV-REPORTS-SURFACE (MH-05): items untouched for ≥ $days days —
	 * last activity = max(item.created_at, last movement of ANY kind)
	 * (AS-01); items younger than the window and zero-qty items are
	 * excluded (AC-7/AC-8).
	 *
	 * @return array{isEstimate: true, days: int, rows: list<array{itemId:int, sku:string, name:string, totalQty:int, lastActivityAt:?int, valueMinor:?int}>, unpricedCount: int}
	 */
	public function deadStock(int $days, int $qtyFactor, ?int $now = null): array
	{
		$factor = $qtyFactor > 0 ? $qtyFactor : 1;
		$now ??= $this->clock !== null ? $this->clock->now() : time();
		$threshold = $now - $days * 86400;
		$lastMove = $this->movements !== null ? $this->movements->lastMovementByItem() : [];
		$sums = $this->balances->sumQtyByItem(null);
		$rows = [];
		$unpriced = 0;
		foreach ($this->items->iterateActive() as $item) {
			$itemId = (int)$item->getId();
			$qty = $sums[$itemId] ?? 0;
			if ($qty <= 0) {
				continue;
			}
			$lastActivity = max($item->getCreatedAt(), $lastMove[$itemId] ?? 0);
			if ($lastActivity >= $threshold) {
				continue; // AC-8: recently created or recently moved
			}
			$price = $item->getLastPriceMinor();
			$value = null;
			if ($price !== null) {
				$value = intdiv($qty * $price + intdiv($factor, 2), $factor);
			} else {
				$unpriced++;
			}
			$rows[] = [
				'itemId' => $itemId,
				'sku' => $item->getSku(),
				'name' => $item->getName(),
				'totalQty' => $qty,
				'lastActivityAt' => $lastActivity,
				'valueMinor' => $value,
			];
		}
		usort($rows, static fn (array $a, array $b): int => $a['lastActivityAt'] <=> $b['lastActivityAt']);
		return [
			'isEstimate' => true,
			'days' => $days,
			'rows' => $rows,
			'unpricedCount' => $unpriced,
		];
	}
}
