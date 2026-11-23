<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\LoanRequest;
use OCA\InventoryCheck\Db\LoanRequestMapper;
use OCA\InventoryCheck\Exception\ConflictException;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Wave C member loan requests: portal submit (person-bound, capped),
 * office approve → checkout + CAS 'fulfilled' inside the movement txn
 * (a raced decide rolls the stock movement back — never a phantom loan),
 * decline/cancel.
 */
class LoanRequestService
{
	private const MAX_OPEN_PER_PERSON = 20;
	private const MAX_PURPOSE = 2000;

	public function __construct(
		private readonly LoanRequestMapper $requests,
		private readonly ItemMapper $items,
		private readonly \OCA\InventoryCheck\Db\PersonMapper $persons,
		private readonly AccessControlService $access,
		private readonly LoanService $loans,
		private readonly Clock $clock,
	) {
	}

	/**
	 * Portal submit — the resolved person is bound server-side; a token
	 * can never request for someone else.
	 */
	public function submitForPerson(
		int $personId,
		int $itemId,
		?int $locationId,
		int $qty,
		?string $purpose,
		?int $wantedFrom,
		?int $wantedTo,
	): LoanRequest {
		$this->assertItem($itemId);
		if ($qty <= 0 || $qty > 1_000_000) {
			throw new ValidationException('validation_failed', '', [['field' => 'qty', 'code' => 'validation_failed']]);
		}
		$purpose = $purpose !== null ? trim($purpose) : null;
		if ($purpose !== null && mb_strlen($purpose) > self::MAX_PURPOSE) {
			throw new ValidationException('validation_failed', '', [['field' => 'purpose', 'code' => 'validation_failed']]);
		}
		if ($wantedFrom !== null && $wantedTo !== null && $wantedTo <= $wantedFrom) {
			throw new ValidationException('validation_failed', '', [['field' => 'wantedTo', 'code' => 'validation_failed']]);
		}
		if ($this->requests->countOpenForPerson($personId) >= self::MAX_OPEN_PER_PERSON) {
			throw new ConflictException('too_many_open_requests');
		}
		$r = new LoanRequest();
		$r->setItemId($itemId);
		$r->setLocationId($locationId);
		$r->setPersonId($personId);
		$r->setRequesterUid(null);
		$r->setQty($qty);
		$r->setPurpose($purpose === '' ? null : $purpose);
		$r->setWantedFrom($wantedFrom);
		$r->setWantedTo($wantedTo);
		$r->setStatus('pending');
		$r->setCreatedAt($this->clock->now());
		return $this->requests->insert($r);
	}

	/** Portal cancel: member cancels own pending request only. */
	public function cancelForPerson(int $personId, int $requestId): void
	{
		$r = $this->findOr404($requestId);
		if ($r->getPersonId() !== $personId) {
			throw new NotFoundException('unknown_request');
		}
		if ($this->requests->tryDecide($requestId, 'cancelled', 'portal', $this->clock->now(), null, null) !== 1) {
			throw new ConflictException('request_not_pending');
		}
	}

	/** @return list<array> office queue */
	public function listPending(string $actorUid, int $limit = 200, int $offset = 0): array
	{
		$this->access->requireOffice($actorUid);
		$itemNames = [];
		$personNames = [];
		return array_map(
			function (LoanRequest $r) use (&$itemNames, &$personNames): array {
				$row = $r->toApi();
				$row['itemName'] = $this->nameOf($itemNames, $this->items, $r->getItemId());
				if ($r->getPersonId() !== null) {
					$row['personName'] = $this->nameOf($personNames, $this->persons, $r->getPersonId(), 'getDisplayName');
				}
				return $row;
			},
			$this->requests->listByStatus('pending', $limit, $offset),
		);
	}

	/** Memoized name lookup — a queue page of 200 rows must not N+1. */
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

	/**
	 * Approve → loan in ONE transaction: the request's CAS transition to
	 * 'fulfilled' runs inside the movement txn; if the row was already
	 * decided the checkout rolls back — no movement, no loan, no oracle.
	 */
	public function approve(string $actorUid, int $requestId, ?int $locationId, ?string $conditionOut, ?string $note): array
	{
		$this->access->requireOffice($actorUid);
		$r = $this->findOr404($requestId);
		if ($r->getStatus() !== 'pending') {
			throw new ConflictException('request_not_pending');
		}
		$personId = $r->getPersonId();
		if ($personId === null) {
			throw new ValidationException('validation_failed', '', [['field' => 'personId', 'code' => 'validation_failed']]);
		}
		$locationId = $locationId ?? $r->getLocationId();
		if ($locationId === null) {
			throw new ValidationException('validation_failed', '', [['field' => 'locationId', 'code' => 'validation_failed']]);
		}
		$now = $this->clock->now();
		$out = $this->loans->checkout(
			$actorUid, $r->getItemId(), $locationId, $personId,
			$r->getQty(), $r->getWantedTo(), null, $conditionOut, $note ?? $r->getPurpose(),
			function (int $loanId) use ($requestId, $actorUid, $now): void {
				if ($this->requests->tryDecide($requestId, 'fulfilled', $actorUid, $now, $loanId, null) !== 1) {
					throw new ConflictException('request_not_pending');
				}
			},
		);
		return ['request' => $this->findOr404($requestId), 'loan' => $out['loan'], 'movement' => $out['movement']];
	}

	public function decline(string $actorUid, int $requestId, ?string $note): LoanRequest
	{
		$this->access->requireOffice($actorUid);
		$this->findOr404($requestId);
		$note = $note !== null ? trim($note) : null;
		if ($note !== null && mb_strlen($note) > self::MAX_PURPOSE) {
			throw new ValidationException('validation_failed', '', [['field' => 'note', 'code' => 'validation_failed']]);
		}
		if ($this->requests->tryDecide($requestId, 'declined', $actorUid, $this->clock->now(), null, $note === '' ? null : $note) !== 1) {
			throw new ConflictException('request_not_pending');
		}
		return $this->findOr404($requestId);
	}

	/** Member's own request list for the portal (all statuses). @return list<array> */
	public function listForPerson(int $personId, int $limit = 50): array
	{
		return array_map(
			static fn (LoanRequest $r): array => $r->toApi(),
			$this->requests->listForPerson($personId, $limit),
		);
	}

	private function findOr404(int $id): LoanRequest
	{
		try {
			return $this->requests->findById($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('unknown_request');
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
}
