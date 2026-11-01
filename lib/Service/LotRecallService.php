<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\LocationMapper;
use OCA\InventoryCheck\Db\LotMapper;
use OCA\InventoryCheck\Db\MovementMapper;
use OCA\InventoryCheck\Exception\NotFoundException;

/**
 * F-23 lot recall: enter a lot code → which items, which locations, how much.
 * Money-free by construction — safe for seats AND web roles.
 */
class LotRecallService
{
	public function __construct(
		private readonly LotMapper $lots,
		private readonly MovementMapper $movements,
		private readonly ItemMapper $items,
		private readonly LocationMapper $locations,
		private readonly BalanceMapper $balances,
	) {
	}

	/**
	 * @return array{lotCode: string, entries: list<array<string, mixed>>}
	 */
	public function lookup(string $actorUid, string $lotCode): array
	{
		unset($actorUid);
		if ($lotCode === '' || !CodeRules::isValidLotCode($lotCode)) {
			throw new NotFoundException('lot_not_found');
		}
		$lots = $this->lots->findByCode($lotCode);
		$entries = [];
		foreach ($lots as $lot) {
			$itemId = (int)$lot->getItemId();
			try {
				$item = $this->items->findById($itemId);
			} catch (\Throwable) {
				continue;
			}
			$perLoc = $this->movements->sumQtyDeltaByItemLotPerLocation($itemId, $lotCode);
			$locRows = [];
			foreach ($perLoc as $locationId => $qty) {
				if ($qty <= 0) {
					continue;
				}
				$loc = $this->locations->findById((int)$locationId);
				$locRows[] = [
					'locationId' => (int)$locationId,
					'locationCode' => $loc->getCode(),
					'locationName' => $loc->getName(),
					'qty' => $qty,
				];
			}
			$entries[] = [
				'itemId' => $itemId,
				'sku' => $item->getSku(),
				'name' => $item->getName(),
				'expiresAt' => $lot->getExpiresAt(),
				'locations' => $locRows,
				'totalQty' => array_sum($perLoc),
			];
		}
		return ['lotCode' => $lotCode, 'entries' => $entries];
	}
}
