<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\LocationMapper;
use OCP\IConfig;
use OCP\IL10N;

/**
 * IV-COMPLIANCE-PACK (MH-06): printable point-in-time Bestandsliste.
 *
 * Pure read: balances x locations x item prices. Values reuse the
 * last-price estimate convention (isEstimate) — the artifact states
 * that honestly and counts unpriced stock.
 */
class StockListService
{
	public function __construct(
		private readonly BalanceMapper $balances,
		private readonly ItemMapper $items,
		private readonly LocationMapper $locations,
		private readonly IConfig $config,
		private readonly IL10N $l10n,
	) {
	}

	/**
	 * Row set for ODS + PDF — assembled once so both artifacts are
	 * byte-equivalent in content (AC-13 scope-equal).
	 *
	 * @return array{
	 *   columns: list<string>,
	 *   rows: list<list<string>>,
	 *   locationNames: list<string>,
	 *   totalQty: float,
	 *   totalValueMinor: int,
	 *   unpriced: int,
	 *   generatedAt: int,
	 *   instanceId: string,
	 *   factor: int,
	 * }
	 */
	public function snapshot(): array
	{
		$factor = QtyScale::factor($this->config);
		$matrix = $this->balances->sumQtyByItemAndLocation();
		$locs = $this->locations->search(true, 1000, 0)['data'];
		$locIds = array_map(static fn ($l) => (int)$l->getId(), $locs);
		$locNames = array_map(static fn ($l) => (string)$l->getCode(), $locs);

		$t = $this->l10n;
		$columns = [$t->t('SKU'), $t->t('Item'), $t->t('Unit')];
		foreach ($locNames as $name) {
			$columns[] = $name;
		}
		$columns[] = $t->t('Total');
		$columns[] = $t->t('Est. value');

		$rows = [];
		$totalValueMinor = 0;
		$unpriced = 0;
		$anyQty = 0.0;
		foreach ($this->items->iterateActive() as $item) {
			$itemId = (int)$item->getId();
			$byLoc = $matrix[$itemId] ?? [];
			$qtyCells = [];
			$total = 0;
			$hasStock = false;
			foreach ($locIds as $locId) {
				$q = (int)($byLoc[$locId] ?? 0);
				if ($q !== 0) {
					$hasStock = true;
				}
				$total += $q;
				$qtyCells[] = QtyScale::isFractional($this->config)
					? number_format($q / $factor, 3, '.', '')
					: (string)$q;
			}
			if (!$hasStock) {
				continue; // point-in-time stock list: only stock on hand
			}
			$anyQty += $total / $factor;
			$price = $item->getLastPriceMinor();
			$valueCell = '';
			if ($price !== null) {
				$minor = intdiv($total * $price + intdiv($factor, 2), $factor);
				$totalValueMinor += $minor;
				$valueCell = number_format($minor / 100, 2, '.', '');
			} else {
				$unpriced++;
			}
			$rows[] = array_merge(
				[(string)$item->getSku(), (string)$item->getName(), (string)$item->getUom()],
				$qtyCells,
				[QtyScale::isFractional($this->config) ? number_format($total / $factor, 3, '.', '') : (string)$total, $valueCell],
			);
		}
		usort($rows, static fn (array $a, array $b): int => strnatcasecmp((string)$a[1], (string)$b[1]));

		return [
			'columns' => $columns,
			'rows' => $rows,
			'locationNames' => $locNames,
			'totalQty' => $anyQty,
			'totalValueMinor' => $totalValueMinor,
			'unpriced' => $unpriced,
			'generatedAt' => time(),
			'instanceId' => (string)$this->config->getSystemValue('instanceid', ''),
			'factor' => $factor,
		];
	}

	/** @param array $snap result of snapshot() */
	public function buildOds(array $snap): string
	{
		$t = $this->l10n;
		$stamp = gmdate('Y-m-d H:i', (int)$snap['generatedAt']) . ' UTC';
		$footer = [
			[''],
			[$t->t('Stock list (point in time)'), $stamp],
			[$t->t('Install'), $snap['instanceId']],
			[$t->t('Estimated total'), number_format(((int)$snap['totalValueMinor']) / 100, 2, '.', '')],
			[$t->t('Items without price: {count}', ['count' => $snap['unpriced']])],
			[''],
			[$t->t('Checked / date / signature'), '____________________________'],
		];
		return (new OdsBuilder())->build([[
			'name' => $t->t('Stock list'),
			'columns' => $snap['columns'],
			'rows' => array_merge($snap['rows'], $footer),
		]], 'InventoryCheck');
	}

	/** @param array $snap result of snapshot() */
	public function buildPdf(array $snap): string
	{
		$t = $this->l10n;
		$stamp = gmdate('Y-m-d H:i', (int)$snap['generatedAt']) . ' UTC';
		$colCount = count($snap['columns']);
		// Give the item column ~2/5 of the width; the rest splits evenly.
		$widths = [0.10, 0.38, 0.07];
		$remaining = max(0.0, 1.0 - array_sum($widths) - 0.14);
		$locCols = $colCount - 5;
		$perLoc = $locCols > 0 ? $remaining / $locCols : 0;
		for ($i = 0; $i < $locCols; $i++) {
			$widths[] = $perLoc;
		}
		$widths[] = 0.07;
		$widths[] = 0.07;

		return SimplePdfBuilder::buildStatement([
			'brand' => 'InventoryCheck',
			'title' => $t->t('Stock list (point in time)'),
			'meta' => [
				[$t->t('Generated'), $stamp],
				[$t->t('Install'), (string)$snap['instanceId']],
			],
			'columns' => $snap['columns'],
			'colWidths' => $widths,
			'rows' => $snap['rows'],
			'totals' => [
				['label' => $t->t('Estimated total'), 'value' => number_format(((int)$snap['totalValueMinor']) / 100, 2, '.', ''), 'strong' => true],
				['label' => $t->t('Items without price'), 'value' => (string)$snap['unpriced']],
			],
			'note' => $t->t('Estimate based on last recorded prices.')
				. "\n" . $t->t('Checked / date / signature') . ': ____________________________',
		]);
	}
}
