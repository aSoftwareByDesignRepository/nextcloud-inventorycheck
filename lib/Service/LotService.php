<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\Lot;
use OCA\InventoryCheck\Db\LotMapper;
use OCA\InventoryCheck\Db\MovementMapper;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * IV-LOT-EXPIRY-PACK — lot stock + expiry view model.
 *
 * One service answers both surfaces: the FEFO picker (sorted lots with
 * remaining qty + state) and the Übersicht watch card. Sorting and state
 * classification are fixed here so web and API consumers see identical
 * ordering (AC-15) — never re-sorted client-side.
 */
final class LotService
{
	public const STATE_OK = 'ok';
	public const STATE_EXPIRING = 'expiring';
	public const STATE_EXPIRED = 'expired';
	public const STATE_EMPTY = 'empty';

	public function __construct(
		private readonly LotMapper $lots,
		private readonly MovementMapper $movements,
		private readonly ItemMapper $items,
		private readonly LocationAclService $locationAcl,
		private readonly RestockConfig $restock,
		private readonly Clock $clock,
	) {
	}

	/**
	 * FEFO picker payload (MH-01): every lot of the item with remaining qty
	 * + state, sorted — in-stock lots first by `expires_at IS NULL,
	 * expires_at ASC` (portable NULLS-LAST), empty lots last.
	 *
	 * Scope: qty sums only actor-visible locations (same rule as
	 * balances/totalQty); $onlyLocationId intersects — an inaccessible
	 * location collapses to uniform 404, never an oracle.
	 *
	 * @return list<array{lotId:int, lotCode:string, qty:int, expiresAt:?int, expiresInDays:?int, state:string}>
	 */
	public function lotsWithStock(string $actorUid, int $itemId, ?int $onlyLocationId = null): array
	{
		try {
			$this->items->findById($itemId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('unknown_item');
		}
		$visible = $this->locationAcl->visibleLocationIds($actorUid);
		if ($onlyLocationId !== null) {
			if ($visible !== null && !in_array($onlyLocationId, $visible, true)) {
				throw new NotFoundException('unknown_location');
			}
			$visible = [$onlyLocationId];
		}
		$qtyByCode = $this->movements->sumQtyDeltaByLotForItem($itemId, $visible);
		$warnSec = $this->restock->expiryWarnDays() * 86400;
		$now = $this->clock->now();

		$rows = [];
		$seen = [];
		foreach ($this->lots->findByItem($itemId) as $lot) {
			$qty = $qtyByCode[$lot->getLotCode()] ?? 0;
			$rows[] = $this->row($lot->getId(), $lot->getLotCode(), $qty, $lot->getExpiresAt(), $now, $warnSec);
			$seen[$lot->getLotCode()] = true;
		}
		// Movement lots with no lot row (e.g. issued before a receive was
		// recorded) stay pickable — expiry unknown, state derived from qty.
		foreach ($qtyByCode as $code => $qty) {
			if (!isset($seen[$code])) {
				$rows[] = $this->row(0, (string)$code, $qty, null, $now, $warnSec);
			}
		}
		usort($rows, self::sortFefo(...));
		return $rows;
	}

	/**
	 * Übersicht watch card (MH-05): in-stock lots expiring within the
	 * configured window, scoped to actor-visible locations, item labels
	 * attached. Empty when the watch is off (threshold 0) or nothing is
	 * near — the caller hides the card (no dead empty state).
	 *
	 * @return list<array{lotId:int, itemId:int, itemName:string, itemSku:string, lotCode:string, qty:int, expiresAt:int, expiresInDays:int, state:string}>
	 */
	public function expiringSoon(string $actorUid, int $limit = 20): array
	{
		$days = $this->restock->expiryWarnDays();
		if ($days === 0) {
			return [];
		}
		$now = $this->clock->now();
		$lots = $this->lots->expiringWithin($now, $days * 86400);
		if ($lots === []) {
			return [];
		}
		$itemIds = array_values(array_unique(array_map(static fn (Lot $l): int => $l->getItemId(), $lots)));
		$qtyMap = $this->movements->sumQtyDeltaByItemLotMap(
			$itemIds,
			$this->locationAcl->visibleLocationIds($actorUid),
		);
		$names = $this->itemNames($itemIds);
		$rows = [];
		foreach ($lots as $lot) {
			$qty = $qtyMap[$lot->getItemId()][$lot->getLotCode()] ?? 0;
			if ($qty <= 0) {
				continue; // NN-04: zero-qty noise never reaches the card
			}
			$expiresAt = (int)$lot->getExpiresAt();
			$meta = $names[$lot->getItemId()] ?? ['name' => '', 'sku' => ''];
			$rows[] = [
				'lotId' => (int)$lot->getId(),
				'itemId' => $lot->getItemId(),
				'itemName' => $meta['name'],
				'itemSku' => $meta['sku'],
				'lotCode' => $lot->getLotCode(),
				'qty' => $qty,
				'expiresAt' => $expiresAt,
				'expiresInDays' => (int)floor(($expiresAt - $now) / 86400),
				'state' => $expiresAt < $now ? self::STATE_EXPIRED : self::STATE_EXPIRING,
			];
		}
		usort($rows, static fn (array $a, array $b): int => $a['expiresAt'] <=> $b['expiresAt']);
		return array_slice($rows, 0, $limit);
	}

	/**
	 * @param list<int> $itemIds
	 * @return array<int, array{name: string, sku: string}>
	 */
	private function itemNames(array $itemIds): array
	{
		$out = [];
		foreach ($itemIds as $id) {
			try {
				$item = $this->items->findById($id);
				$out[$id] = ['name' => $item->getName(), 'sku' => $item->getSku()];
			} catch (DoesNotExistException) {
				$out[$id] = ['name' => '', 'sku' => ''];
			}
		}
		return $out;
	}

	/** @return array{lotId:int, lotCode:string, qty:int, expiresAt:?int, expiresInDays:?int, state:string} */
	private function row(int $lotId, string $lotCode, int $qty, ?int $expiresAt, int $now, int $warnSec): array
	{
		return [
			'lotId' => $lotId,
			'lotCode' => $lotCode,
			'qty' => $qty,
			'expiresAt' => $expiresAt,
			'expiresInDays' => $expiresAt === null ? null : (int)floor(($expiresAt - $now) / 86400),
			'state' => self::classify($qty, $expiresAt, $now, $warnSec),
		];
	}

	/**
	 * State boundaries (spec §9): empty wins over expiry states; `expired`
	 * is strictly before now (expires_at is an end-of-day epoch — a lot is
	 * good through that date); `expiring` only inside the warn window and
	 * only when the watch is on (warnSec > 0).
	 */
	public static function classify(int $qty, ?int $expiresAt, int $now, int $warnSec): string
	{
		if ($qty <= 0) {
			return self::STATE_EMPTY;
		}
		if ($expiresAt === null) {
			return self::STATE_OK;
		}
		if ($expiresAt < $now) {
			return self::STATE_EXPIRED;
		}
		if ($warnSec > 0 && $expiresAt <= $now + $warnSec) {
			return self::STATE_EXPIRING;
		}
		return self::STATE_OK;
	}

	/**
	 * In-stock first by portable NULLS-LAST expiry; empty lots last.
	 *
	 * @param array{qty:int, expiresAt:?int} $a
	 * @param array{qty:int, expiresAt:?int} $b
	 */
	private static function sortFefo(array $a, array $b): int
	{
		$aEmpty = $a['qty'] <= 0 ? 1 : 0;
		$bEmpty = $b['qty'] <= 0 ? 1 : 0;
		if ($aEmpty !== $bEmpty) {
			return $aEmpty <=> $bEmpty;
		}
		$aNull = $a['expiresAt'] === null ? 1 : 0;
		$bNull = $b['expiresAt'] === null ? 1 : 0;
		if ($aNull !== $bNull) {
			return $aNull <=> $bNull;
		}
		return ($a['expiresAt'] ?? 0) <=> ($b['expiresAt'] ?? 0);
	}
}
