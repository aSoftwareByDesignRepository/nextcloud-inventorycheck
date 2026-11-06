<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\MovementMapper;
use OCP\IConfig;

class LowStockService
{
	/** Wave B3: per-location reorder hints, off by default (server-wide S10 stays the default view). */
	public const KEY_LOCATION_REORDER_HINT_ENABLED = 'location_reorder_hint_enabled';

	public function __construct(
		private readonly ItemMapper $items,
		private readonly BalanceMapper $balances,
		private readonly IConfig $config,
		private readonly LocationAclService $locationAcl,
		private readonly ?AccessControlService $access = null,
		private readonly ?RestockConfig $restock = null,
		private readonly ?MovementMapper $movements = null,
		private readonly ?Clock $clock = null,
	) {
	}

	/**
	 * S10: active items with reorder_level > 0 and SUM(qty) < reorder_level.
	 * Wave C3: SUM only over locations the actor may see.
	 *
	 * Restock F-01/F-02: office callers additionally get est. cost per row
	 * and a meta block (totals, per-category budget states, stale markers).
	 * Field callers get *no* money keys at all (A7 / NN-03).
	 *
	 * @return array{data: list<array<string, mixed>>, total: int, limit: int, offset: int, meta?: array<string, mixed>}
	 */
	public function list(string $actorUid, int $limit, int $offset): array
	{
		$visible = $this->locationAcl->visibleLocationIds($actorUid);
		$sums = $this->balances->sumQtyByItem($visible);
		$isOffice = $this->access === null || $this->access->isOffice($actorUid);
		$factor = QtyScale::factor($this->config);
		$now = $this->restock !== null && $this->access !== null ? (new \DateTimeImmutable())->getTimestamp() : null;
		$staleCutoff = $now !== null && $this->restock !== null
			? $now - ($this->restock->onOrderStaleDays() * 86400)
			: null;
		$budgets = $isOffice && $this->restock !== null ? $this->restock->budgets() : [];
		$byCategory = [];

		// F-10: coverage mode — suggested qty covers consumption over the
		// velocity window (plus supplier lead time) instead of the static
		// target/deficit basis. Never below the reorder deficit.
		$coverageMode = $this->restock !== null && $this->restock->suggestionMode() === 'coverage';
		$velocity = [];
		if ($coverageMode && $this->movements !== null && $this->clock !== null) {
			$window = $this->restock->velocityWindowDays();
			$issued = $this->movements->sumIssueQtyByItemSince($this->clock->now() - ($window * 86400));
			foreach ($issued as $itemId => $qty) {
				$velocity[(int)$itemId] = $window > 0 ? $qty / $window : 0.0; // avg per day (storage units)
			}
		}

		// F-26: usage-spike anomaly — last-7d issues exceed 3× the baseline
		// (avg per 7d over the prior 21 days). Informational flag only.
		$spikes = [];
		if ($isOffice && $this->movements !== null && $this->clock !== null) {
			$recent = $this->movements->sumIssueQtyByItemSince($this->clock->now() - 7 * 86400);
			$baseline = $this->movements->sumIssueQtyByItemSince($this->clock->now() - 28 * 86400);
			foreach ($recent as $itemId => $qty) {
				$base7d = (($baseline[$itemId] ?? 0) - $qty) / 3.0;
				if ($qty >= 5 && $qty > $base7d * 3) {
					$spikes[(int)$itemId] = true;
				}
			}
		}

		$rows = [];
		foreach ($this->items->iterateActive() as $item) {
			$total = $sums[(int)$item->getId()] ?? 0;
			if (!LowStockQuery::isLowStock(true, $item->getReorderLevel(), $total)) {
				continue;
			}
			$suggested = SuggestedOrder::qty($total, $item->getReorderLevel(), $item->getTargetStock());
			if ($coverageMode) {
				$lead = max(0, (int)($item->getSupplierLeadDays() ?? 0));
				$window = $this->restock->velocityWindowDays();
				$need = (int)ceil(($velocity[(int)$item->getId()] ?? 0.0) * ($window + $lead)) - $total;
				if ($need > $suggested) {
					$suggested = $need;
				}
			}
			$itemApi = MoneyVisibility::itemForWeb($item->toApi(), $isOffice);
			$row = [
				'item' => $itemApi,
				'totalQty' => $total,
				'reorderLevel' => $item->getReorderLevel(),
				'targetStock' => $item->getTargetStock(),
				'suggestedQty' => $suggested,
				'deficit' => LowStockQuery::deficit($total, $item->getReorderLevel()),
				'criticality' => $item->getCriticality(),
			];
			if ($isOffice) {
				$est = RestockEstimate::lineMinor(
					$suggested,
					$item->getOrderPackQty(),
					$item->getOrderPackPriceMinor(),
					$item->getLastPriceMinor(),
					$factor,
				);
				$row['packs'] = RestockEstimate::packs($suggested, $item->getOrderPackQty());
				$row['estLineCostMinor'] = $est;
				if ($staleCutoff !== null && $item->getOnOrderAt() !== null && $item->getOnOrderAt() < $staleCutoff) {
					$row['onOrderStale'] = true;
				}
				if (isset($spikes[(int)$item->getId()])) {
					$row['usageSpike'] = true;
				}
				$cat = $item->getCategory() ?? RestockConfig::UNCATEGORIZED;
				if (!isset($byCategory[$cat])) {
					$byCategory[$cat] = ['est' => 0, 'unpriced' => 0, 'stale' => 0];
				}
				if ($est === null) {
					$byCategory[$cat]['unpriced']++;
				} else {
					$byCategory[$cat]['est'] += $est;
				}
				if (($row['onOrderStale'] ?? false) === true) {
					$byCategory[$cat]['stale']++;
				}
			}
			$rows[] = $row;
		}
		// F-11: critical items sort first, then worst deficit.
		usort($rows, static function (array $a, array $b): int {
			$ca = ($a['criticality'] ?? 'none') === 'critical' ? 0 : 1;
			$cb = ($b['criticality'] ?? 'none') === 'critical' ? 0 : 1;
			if ($ca !== $cb) {
				return $ca <=> $cb;
			}
			if ($a['deficit'] !== $b['deficit']) {
				return $a['deficit'] <=> $b['deficit'];
			}
			return $a['item']['id'] <=> $b['item']['id'];
		});
		$total = count($rows);
		$data = array_slice($rows, $offset, $limit);
		$out = ['data' => $data, 'total' => $total, 'limit' => $limit, 'offset' => $offset];

		if ($isOffice) {
			// Monthly cycle: received spend since the 1st consumes the budget —
			// remaining = budget − MTD spend; the owner emergency ceiling sits
			// on top and only produces the over_emergency state.
			$emergency = $this->restock !== null ? ($this->restock->emergencyBudgetMinor() ?? 0) : 0;
			$mtdByCat = [];
			if ($this->movements !== null && $this->restock !== null) {
				$cycleStart = (new \DateTimeImmutable('first day of this month'))->setTime(0, 0, 0)->getTimestamp();
				$mtdByCat = $this->movements->receivedSpendByCategorySince($cycleStart, $now);
				if (isset($mtdByCat[''])) {
					$mtdByCat[RestockConfig::UNCATEGORIZED] = ($mtdByCat[RestockConfig::UNCATEGORIZED] ?? 0) + $mtdByCat[''];
					unset($mtdByCat['']);
				}
			}
			$estTotal = 0;
			$unpriced = 0;
			$staleTotal = 0;
			$states = [];
			$catMeta = [];
			foreach ($byCategory as $cat => $agg) {
				$budget = $budgets[$cat] ?? null;
				$mtd = $mtdByCat[$cat] ?? 0;
				$state = BudgetClassifier::stateExtended($budget, $agg['est'], $mtd, $emergency);
				$states[] = $state;
				$estTotal += $agg['est'];
				$unpriced += $agg['unpriced'];
				$staleTotal += $agg['stale'];
				$catMeta[] = [
					'category' => $cat,
					'estTotalMinor' => $agg['est'],
					'unpricedCount' => $agg['unpriced'],
					'budgetMinor' => ($budget !== null && $budget > 0) ? $budget : null,
					'spentMtdMinor' => $mtd,
					'remainingMinor' => BudgetClassifier::remaining($budget, $mtd),
					'emergencyMinor' => $emergency > 0 ? $emergency : null,
					'budgetState' => $state,
					'budgetDeltaMinor' => BudgetClassifier::remaining($budget, $mtd) !== null
						? BudgetClassifier::remaining($budget, $mtd) - $agg['est']
						: null,
				];
			}
			usort($catMeta, static fn (array $a, array $b): int => strnatcasecmp($a['category'], $b['category']));
			$out['meta'] = [
				'estTotalMinor' => $estTotal,
				'unpricedCount' => $unpriced,
				'staleOnOrderCount' => $staleTotal,
				'byCategory' => $catMeta,
				'budgetState' => BudgetClassifier::worst($states),
			];
		}
		return $out;
	}

	/**
	 * Post-receive budget snapshot for the item's category (mobile scan
	 * feedback). Office only — money keys never reach field callers
	 * (A7 / NN-03). Callers compute this AFTER the receive commits, so the
	 * just-booked spend is already inside spentMtdMinor. A null return simply
	 * means "no budget story to tell" (no budget set, non-office actor, or the
	 * optional deps are absent) — the response then carries no budget block.
	 *
	 * @return array{category: string, budgetMinor: int, spentMtdMinor: int, remainingMinor: int|null, emergencyMinor: int|null}|null
	 */
	public function budgetForItem(string $actorUid, int $itemId): ?array
	{
		if ($this->access === null || $this->restock === null || $this->movements === null) {
			return null;
		}
		if (!$this->access->isOffice($actorUid)) {
			return null;
		}
		try {
			$item = $this->items->findById($itemId);
		} catch (\Throwable) {
			// Informational block only — a lookup hiccup after a committed
			// receive must never fail the booking response.
			return null;
		}
		$cat = $item->getCategory() ?? RestockConfig::UNCATEGORIZED;
		$budget = $this->restock->budgets()[$cat] ?? null;
		if ($budget === null || $budget <= 0) {
			return null;
		}
		$now = (new \DateTimeImmutable())->getTimestamp();
		$cycleStart = (new \DateTimeImmutable('first day of this month'))->setTime(0, 0, 0)->getTimestamp();
		$mtdByCat = $this->movements->receivedSpendByCategorySince($cycleStart, $now);
		// Uncategorized buckets receive the NULL-category spend too (same merge as list()).
		$mtd = ($mtdByCat[$cat] ?? 0) + ($cat === RestockConfig::UNCATEGORIZED ? ($mtdByCat[''] ?? 0) : 0);
		$emergency = $this->restock->emergencyBudgetMinor() ?? 0;
		return [
			'category' => $cat,
			'budgetMinor' => $budget,
			'spentMtdMinor' => $mtd,
			'remainingMinor' => BudgetClassifier::remaining($budget, $mtd),
			'emergencyMinor' => $emergency > 0 ? $emergency : null,
		];
	}

	public function isPerLocationHintEnabled(): bool
	{
		return $this->config->getAppValue(Application::APP_ID, self::KEY_LOCATION_REORDER_HINT_ENABLED, '0') === '1';
	}

	public function setPerLocationHintEnabled(bool $enabled): void
	{
		$this->config->setAppValue(Application::APP_ID, self::KEY_LOCATION_REORDER_HINT_ENABLED, $enabled ? '1' : '0');
	}

	/**
	 * Wave B3: same S10 predicate but evaluated per (item, location) pair
	 * instead of the item-wide sum. Only active when the app-admin toggle is on.
	 * Wave C3: field users only see locations they can access.
	 *
	 * @return array{data: list<array<string, mixed>>, total: int, limit: int, offset: int}
	 */
	public function listPerLocation(string $actorUid, int $limit, int $offset): array
	{
		if (!$this->isPerLocationHintEnabled()) {
			return ['data' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset];
		}
		$visible = $this->locationAcl->visibleLocationIds($actorUid);
		if ($visible !== null && $visible === []) {
			return ['data' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset];
		}
		$perLocationQty = $this->balances->sumQtyByItemAndLocation();
		$rows = [];
		foreach ($this->items->iterateActive() as $item) {
			$itemId = (int)$item->getId();
			$reorder = $item->getReorderLevel();
			if ($reorder <= 0 || !isset($perLocationQty[$itemId])) {
				continue;
			}
			foreach ($perLocationQty[$itemId] as $locationId => $qty) {
				if ($visible !== null && !in_array($locationId, $visible, true)) {
					continue;
				}
				if (!LowStockQuery::isLowStock(true, $reorder, $qty)) {
					continue;
				}
				$rows[] = [
					'item' => $item->toApi(),
					'locationId' => $locationId,
					'qty' => $qty,
					'reorderLevel' => $reorder,
					'deficit' => LowStockQuery::deficit($qty, $reorder),
				];
			}
		}
		usort($rows, static function (array $a, array $b): int {
			if ($a['deficit'] !== $b['deficit']) {
				return $a['deficit'] <=> $b['deficit'];
			}
			if ($a['item']['id'] !== $b['item']['id']) {
				return $a['item']['id'] <=> $b['item']['id'];
			}
			return $a['locationId'] <=> $b['locationId'];
		});
		$total = count($rows);
		$data = array_slice($rows, $offset, $limit);
		return ['data' => $data, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
	}
}
