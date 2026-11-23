<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\LocationMapper;
use OCA\InventoryCheck\Db\Reservation;
use OCA\InventoryCheck\Db\ReservationMapper;
use OCA\InventoryCheck\Exception\ConflictException;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Wave C reservations: qty + date-range holds, availability =
 * balance − open reservations, CAS terminal transitions, convert→checkout
 * inside the movement txn, expiry sweep for stale holds.
 */
class ReservationService
{
	private const MAX_QTY = 1_000_000;

	public function __construct(
		private readonly ReservationMapper $reservations,
		private readonly ItemMapper $items,
		private readonly LocationMapper $locations,
		private readonly BalanceMapper $balances,
		private readonly AccessControlService $access,
		private readonly LocationAclService $locationAcl,
		private readonly \OCA\InventoryCheck\Db\PersonMapper $persons,
		private readonly LoanService $loans,
		private readonly Clock $clock,
	) {
	}

	public function create(
		string $actorUid,
		int $itemId,
		int $locationId,
		int $qty,
		int $fromTs,
		int $toTs,
		?int $personId,
		?string $eventLabel,
	): Reservation {
		$this->access->requireOffice($actorUid);
		$this->assertItem($itemId);
		$this->assertLocation($locationId);
		if ($qty <= 0 || $qty > self::MAX_QTY) {
			throw new ValidationException('validation_failed', '', [['field' => 'qty', 'code' => 'validation_failed']]);
		}
		$now = $this->clock->now();
		if ($fromTs <= 0 || $toTs <= $fromTs || $fromTs > 4_102_444_800 || $toTs > 4_102_444_800) {
			throw new ValidationException('validation_failed', '', [['field' => 'toTs', 'code' => 'validation_failed']]);
		}
		$eventLabel = $eventLabel !== null ? trim($eventLabel) : null;
		if ($eventLabel !== null && mb_strlen($eventLabel) > 190) {
			throw new ValidationException('validation_failed', '', [['field' => 'eventLabel', 'code' => 'validation_failed']]);
		}
		$r = new Reservation();
		$r->setItemId($itemId);
		$r->setLocationId($locationId);
		$r->setPersonId($personId);
		$r->setEventLabel($eventLabel === '' ? null : $eventLabel);
		$r->setQty($qty);
		$r->setFromTs($fromTs);
		$r->setToTs($toTs);
		$r->setStatus('reserved');
		$r->setCreatedBy($actorUid);
		$r->setCreatedAt($now);
		return $this->reservations->insert($r);
	}

	/** @return list<array> */
	public function listForItem(string $actorUid, int $itemId): array
	{
		$this->access->requireOffice($actorUid);
		$this->assertItem($itemId);
		$visible = $this->locationAcl->visibleLocationIds($actorUid);
		$rows = array_filter(
			$this->reservations->listForItem($itemId),
			static fn (Reservation $r): bool => $visible === null || in_array($r->getLocationId(), $visible, true),
		);
		$locNames = [];
		$personNames = [];
		return array_map(function (Reservation $r) use (&$locNames, &$personNames): array {
			$row = $r->toApi();
			$row['locationName'] = $this->nameOf($locNames, $this->locations, $r->getLocationId());
			if ($r->getPersonId() !== null) {
				$row['personName'] = $this->nameOf($personNames, $this->persons, $r->getPersonId(), 'getDisplayName');
			}
			return $row;
		}, array_values($rows));
	}

	/** Memoized name lookup — lists must not N+1. */
	private function nameOf(array &$cache, object $mapper, int $id, string $getter = 'getName'): ?string
	{
		if (!array_key_exists($id, $cache)) {
			try {
				$cache[$id] = $mapper->findById($id)->{$getter}();
			} catch (\Throwable) {
				$cache[$id] = null;
			}
		}
		return $cache[$id];
	}

	public function cancel(string $actorUid, int $id): void
	{
		$this->access->requireOffice($actorUid);
		$r = $this->findOr404($id);
		$this->assertVisible($actorUid, $r);
		if ($this->reservations->tryTransition($id, 'cancelled') !== 1) {
			throw new ConflictException('reservation_not_open');
		}
	}

	/**
	 * Convert → checkout: the CAS transition to 'fulfilled' rides the
	 * movement txn via LoanService::checkout's afterLoanInsert hook —
	 * a raced conversion rolls the stock movement back. Soft-warn is the
	 * caller's UX concern (Q-C1), the server just performs the checkout.
	 */
	public function convert(string $actorUid, int $id, ?string $conditionOut, ?string $note): array
	{
		$this->access->requireOffice($actorUid);
		$r = $this->findOr404($id);
		$this->assertVisible($actorUid, $r);
		if (!$r->isOpen()) {
			throw new ConflictException('reservation_not_open');
		}
		$personId = $r->getPersonId();
		if ($personId === null) {
			throw new ValidationException('validation_failed', '', [['field' => 'personId', 'code' => 'validation_failed']]);
		}
		$out = $this->loans->checkout(
			$actorUid, $r->getItemId(), $r->getLocationId(), $personId,
			$r->getQty(), $r->getToTs(), null, $conditionOut, $note ?? $r->getEventLabel(),
			function (int $loanId) use ($id): void {
				if ($this->reservations->tryTransition($id, 'fulfilled', $loanId) !== 1) {
					throw new ConflictException('reservation_not_open');
				}
			},
		);
		return $out;
	}

	/**
	 * Availability for the portal badge + office display:
	 * available = balance − open reservations (never below zero).
	 * @return array{balance: int, reserved: int, available: int}
	 */
	public function availability(int $itemId, int $locationId): array
	{
		$now = $this->clock->now();
		$bal = $this->balances->findPair($itemId, $locationId);
		$balance = $bal === null ? 0 : max(0, $bal->getQty());
		$reserved = $this->reservations->reservedQtyInWindow($itemId, $locationId, $now);
		return [
			'balance' => $balance,
			'reserved' => $reserved,
			'available' => max(0, $balance - $reserved),
		];
	}

	/** Expiry sweep — CAS makes reruns idempotent. Returns rows expired. */
	public function sweepExpired(): int
	{
		$now = $this->clock->now();
		$n = 0;
		foreach ($this->reservations->expiredOpen($now) as $r) {
			if ($this->reservations->tryTransition($r->getId(), 'expired') === 1) {
				$n++;
			}
		}
		return $n;
	}

	private function findOr404(int $id): Reservation
	{
		try {
			return $this->reservations->findById($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('unknown_reservation');
		}
	}

	private function assertVisible(string $actorUid, Reservation $r): void
	{
		if (!$this->locationAcl->canAccessLocation($actorUid, $r->getLocationId())) {
			throw new NotFoundException('unknown_reservation');
		}
	}

	private function assertItem(int $itemId): void
	{
		try {
			$this->items->findById($itemId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('unknown_item');
		}
	}

	private function assertLocation(int $locationId): void
	{
		try {
			$this->locations->findById($locationId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('unknown_location');
		}
	}
}
