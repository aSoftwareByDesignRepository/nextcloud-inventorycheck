<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Db\Balance;
use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\LocationMapper;
use OCA\InventoryCheck\Db\Movement;
use OCA\InventoryCheck\Db\MovementMapper;
use OCA\InventoryCheck\Exception\InsufficientStockException;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\PermissionDeniedException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCP\IDBConnection;

/**
 * Append-only stock ledger (SPEC §5).
 *
 * Every qty change posts one immutable movement row and updates iv_balances
 * in the same transaction. Lock protocol (global order, deadlock-free):
 *   1. item row (shared) — conflicts with exclusive deactivate/delete (S5/S6)
 *   2. location row(s) (shared, ascending id)
 *   3. balance rows FOR UPDATE in ascending (item_id, location_id) order —
 *      deadlock-free for opposite transfers (S2)
 * Active checks run on the locked rows, so a movement can never commit
 * against an entity that a concurrent request just deactivated or deleted.
 */
class MovementService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly MovementMapper $movements,
		private readonly BalanceMapper $balances,
		private readonly ItemMapper $items,
		private readonly LocationMapper $locations,
		private readonly AccessControlService $access,
		private readonly Clock $clock,
	) {
	}

	/**
	 * @return array{movements: list<array<string, mixed>>, balances: list<array<string, mixed>>}
	 */
	public function receive(string $actorUid, int $itemId, int $locationId, int $qty, ?string $reason): array
	{
		$this->access->requireOffice($actorUid);
		return $this->postSingle($actorUid, 'receive', $itemId, $locationId, $qty, $reason);
	}

	/**
	 * @return array{movements: list<array<string, mixed>>, balances: list<array<string, mixed>>}
	 */
	public function issue(string $actorUid, int $itemId, int $locationId, int $qty, ?string $reason): array
	{
		return $this->postSingle($actorUid, 'issue', $itemId, $locationId, $qty, $reason);
	}

	/**
	 * @return array{movements: list<array<string, mixed>>, balances: list<array<string, mixed>>}
	 */
	public function transfer(
		string $actorUid,
		int $itemId,
		int $fromLocationId,
		int $toLocationId,
		int $qty,
		?string $reason,
	): array {
		if ($fromLocationId === $toLocationId) {
			throw new ValidationException('same_location');
		}
		if (!MovementMath::isValidMovementQty($qty)) {
			throw new ValidationException('invalid_qty');
		}
		$reason = $this->normalizeReason($reason);

		$allowNeg = $this->access->allowNegativeStock();
		$now = $this->clock->now();
		$group = $this->uuidV4();

		$this->db->beginTransaction();
		try {
			$this->lockActiveItem($itemId);
			$lockedLocs = $this->lockActiveLocations([$fromLocationId, $toLocationId]);
			$fromLoc = $lockedLocs[$fromLocationId];

			$this->balances->ensureZeroRow($itemId, $fromLocationId, $now);
			$this->balances->ensureZeroRow($itemId, $toLocationId, $now);
			$locked = $this->balances->lockPairs([
				['itemId' => $itemId, 'locationId' => $fromLocationId],
				['itemId' => $itemId, 'locationId' => $toLocationId],
			]);
			$fromBal = $locked[$itemId . ':' . $fromLocationId];
			$toBal = $locked[$itemId . ':' . $toLocationId];

			$outAfter = MovementMath::applyDelta($fromBal->getQty(), -$qty);
			$inAfter = MovementMath::applyDelta($toBal->getQty(), $qty);

			if (MovementMath::isInsufficientStock($fromBal->getQty(), $outAfter, $allowNeg)) {
				throw new InsufficientStockException($fromBal->getQty(), $fromLoc->getCode());
			}
			if (!MovementMath::isValidBalance($outAfter) || !MovementMath::isValidBalance($inAfter)) {
				throw new ValidationException('qty_out_of_range');
			}

			$this->updateBalance($fromBal, $outAfter, $now);
			$this->updateBalance($toBal, $inAfter, $now);

			$outMov = $this->insertMovement(
				$itemId, $fromLocationId, 'transfer_out', -$qty, $outAfter,
				$group, $toLocationId, $reason, $actorUid, $now,
			);
			$inMov = $this->insertMovement(
				$itemId, $toLocationId, 'transfer_in', $qty, $inAfter,
				$group, $fromLocationId, $reason, $actorUid, $now,
			);

			$this->db->commit();
			return [
				'movements' => [$outMov->toApi(), $inMov->toApi()],
				'balances' => [$fromBal->toApi(), $toBal->toApi()],
			];
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * @return array{movements: list<array<string, mixed>>, balances: list<array<string, mixed>>}
	 */
	public function adjust(
		string $actorUid,
		int $itemId,
		int $locationId,
		string $mode,
		?int $qty,
		?int $qtyDelta,
		?string $reason,
	): array {
		$this->access->requireOffice($actorUid);
		$reason = $this->normalizeReason($reason);
		$allowNeg = $this->access->allowNegativeStock();
		$now = $this->clock->now();

		$this->db->beginTransaction();
		try {
			$this->lockActiveItem($itemId);
			$this->lockActiveLocations([$locationId]);
			$this->balances->ensureZeroRow($itemId, $locationId, $now);
			$locked = $this->balances->lockPairs([
				['itemId' => $itemId, 'locationId' => $locationId],
			]);
			$bal = $locked[$itemId . ':' . $locationId];

			try {
				$computed = AdjustSemantics::compute($mode, $bal->getQty(), $qty, $qtyDelta, $allowNeg);
			} catch (ValidationException $e) {
				if ($e->getErrorCode() === 'would_go_negative') {
					throw new InsufficientStockException($bal->getQty());
				}
				throw $e;
			}

			$this->updateBalance($bal, $computed['qtyAfter'], $now);
			$mov = $this->insertMovement(
				$itemId, $locationId, 'adjust', $computed['delta'], $computed['qtyAfter'],
				null, null, $reason, $actorUid, $now,
			);

			$this->db->commit();
			return [
				'movements' => [$mov->toApi()],
				'balances' => [$bal->toApi()],
			];
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Scan endpoint (S12): resolve code then dispatch by kind.
	 * Device callers are treated as field (caller passes $asOffice=false).
	 *
	 * @return array{movements: list<array<string, mixed>>, balances: list<array<string, mixed>>}
	 */
	public function scan(
		string $actorUid,
		string $code,
		string $kind,
		int $locationId,
		?int $toLocationId,
		?int $qty,
		?int $qtyDelta,
		?string $reason,
		bool $asOffice,
	): array {
		$item = $this->items->resolveByCode(CodeRules::trim($code));
		if ($item === null) {
			throw new NotFoundException('code_not_found');
		}
		$kind = strtolower(trim($kind));
		$itemId = (int)$item->getId();

		return match ($kind) {
			'receive' => $asOffice
				? $this->receive($actorUid, $itemId, $locationId, (int)$qty, $reason)
				: throw new PermissionDeniedException(),
			'issue' => $this->issue($actorUid, $itemId, $locationId, (int)$qty, $reason),
			'transfer' => $toLocationId === null || $toLocationId <= 0
				? throw new ValidationException('validation_failed', '', [
					['field' => 'toLocationId', 'code' => 'validation_failed'],
				])
				: $this->transfer(
					$actorUid, $itemId, $locationId, $toLocationId, (int)$qty, $reason,
				),
			'adjust' => $asOffice
				? $this->adjust($actorUid, $itemId, $locationId, 'delta', null, $qtyDelta, $reason)
				: throw new PermissionDeniedException(),
			default => throw new ValidationException('validation_failed', '', [
				['field' => 'kind', 'code' => 'validation_failed'],
			]),
		};
	}

	/**
	 * @return array{data: list<array<string, mixed>>, total: int, limit: int, offset: int}
	 */
	public function list(
		?string $kind,
		?int $itemId,
		?int $locationId,
		?int $from,
		?int $to,
		?string $transferGroup,
		int $limit,
		int $offset,
	): array {
		if ($from !== null && $to !== null && $from > $to) {
			throw new ValidationException('invalid_query');
		}
		$result = $this->movements->search($kind, $itemId, $locationId, $from, $to, $transferGroup, $limit, $offset);
		return [
			'data' => array_map(static fn (Movement $m) => $m->toApi(), $result['data']),
			'total' => $result['total'],
			'limit' => $limit,
			'offset' => $offset,
		];
	}

	/**
	 * @return array{movements: list<array<string, mixed>>, balances: list<array<string, mixed>>}
	 */
	private function postSingle(
		string $actorUid,
		string $kind,
		int $itemId,
		int $locationId,
		int $qty,
		?string $reason,
	): array {
		if (!MovementMath::isValidMovementQty($qty)) {
			throw new ValidationException('invalid_qty');
		}
		$reason = $this->normalizeReason($reason);
		$allowNeg = $this->access->allowNegativeStock();
		$delta = MovementMath::deltaForKind($kind, $qty);
		$now = $this->clock->now();

		$this->db->beginTransaction();
		try {
			$this->lockActiveItem($itemId);
			$loc = $this->lockActiveLocations([$locationId])[$locationId];
			$this->balances->ensureZeroRow($itemId, $locationId, $now);
			$locked = $this->balances->lockPairs([
				['itemId' => $itemId, 'locationId' => $locationId],
			]);
			$bal = $locked[$itemId . ':' . $locationId];
			$qtyAfter = MovementMath::applyDelta($bal->getQty(), $delta);

			if (MovementMath::isInsufficientStock($bal->getQty(), $qtyAfter, $allowNeg)) {
				throw new InsufficientStockException($bal->getQty(), $loc->getCode());
			}
			if (!MovementMath::isValidBalance($qtyAfter)) {
				throw new ValidationException('qty_out_of_range');
			}

			$this->updateBalance($bal, $qtyAfter, $now);
			$mov = $this->insertMovement(
				$itemId, $locationId, $kind, $delta, $qtyAfter,
				null, null, $reason, $actorUid, $now,
			);

			$this->db->commit();
			return [
				'movements' => [$mov->toApi()],
				'balances' => [$bal->toApi()],
			];
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	private function updateBalance(Balance $bal, int $qtyAfter, int $now): void
	{
		$bal->setQty($qtyAfter);
		$bal->setUpdatedAt($now);
		$this->balances->update($bal);
	}

	private function insertMovement(
		int $itemId,
		int $locationId,
		string $kind,
		int $qtyDelta,
		int $qtyAfter,
		?string $transferGroup,
		?int $counterpartyLocId,
		?string $reason,
		string $actorUid,
		int $now,
	): Movement {
		$m = new Movement();
		$m->setItemId($itemId);
		$m->setLocationId($locationId);
		$m->setKind($kind);
		$m->setQtyDelta($qtyDelta);
		$m->setQtyAfter($qtyAfter);
		$m->setTransferGroup($transferGroup);
		$m->setCounterpartyLocId($counterpartyLocId);
		$m->setReason($reason);
		$m->setRefType(null);
		$m->setRefId(null);
		$m->setCreatedAt($now);
		$m->setCreatedBy($actorUid);
		return $this->movements->insert($m);
	}

	/**
	 * Shared-lock the item row inside the open transaction and verify it is
	 * active. Conflicts with the exclusive lock taken by deactivate/delete,
	 * closing the S5/S6 TOCTOU window.
	 */
	private function lockActiveItem(int $itemId): \OCA\InventoryCheck\Db\Item
	{
		$item = $this->items->lockById($itemId, false);
		if (!$item->getActive()) {
			throw new ValidationException('inactive_item');
		}
		return $item;
	}

	/**
	 * Shared-lock location rows in ascending id order (global lock ordering)
	 * and verify each is active.
	 *
	 * @param list<int> $locationIds
	 * @return array<int, \OCA\InventoryCheck\Db\Location> keyed by location id
	 */
	private function lockActiveLocations(array $locationIds): array
	{
		$ids = array_values(array_unique($locationIds));
		sort($ids);
		$out = [];
		foreach ($ids as $id) {
			$loc = $this->locations->lockById($id, false);
			if (!$loc->getActive()) {
				throw new ValidationException('inactive_location');
			}
			$out[$id] = $loc;
		}
		return $out;
	}

	private function normalizeReason(?string $reason): ?string
	{
		if ($reason === null) {
			return null;
		}
		$reason = CodeRules::trim($reason);
		if ($reason === '') {
			return null;
		}
		if (mb_strlen($reason) > 512) {
			throw new ValidationException('validation_failed', '', [
				['field' => 'reason', 'code' => 'validation_failed'],
			]);
		}
		return $reason;
	}

	private function uuidV4(): string
	{
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}
}
