<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\Loan;
use OCA\InventoryCheck\Db\LoanMapper;
use OCA\InventoryCheck\Db\LocationMapper;
use OCA\InventoryCheck\Db\Person;
use OCA\InventoryCheck\Db\PersonMapper;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\ValidationException;
use Psr\Log\LoggerInterface;

/**
 * IV-CUSTODY-LENDING Wave A: loan lifecycle. Write paths delegate the
 * stock-side commit to MovementService so balance + loan + movement +
 * journal land in one txn.
 */
final class LoanService
{
	private const CONDITIONS_OUT = ['ok', 'used', 'defect'];
	private const CONDITIONS_IN = ['ok', 'damaged', 'defect'];

	public function __construct(
		private readonly LoanMapper $loans,
		private readonly PersonMapper $persons,
		private readonly ItemMapper $items,
		private readonly LocationMapper $locations,
		private readonly LocationAclService $locationAcl,
		private readonly MovementService $movements,
		private readonly AccessControlService $access,
		private readonly Clock $clock,
		private readonly LoggerInterface $logger,
	) {
	}

	/** Office+ custody checkout; person must be active & not anonymized. */
	public function checkout(
		string $actorUid,
		int $itemId,
		int $locationId,
		int $personId,
		int $qty,
		?int $dueAt,
		?string $lotCode,
		?string $conditionOut,
		?string $note,
	): array {
		$this->access->requireOffice($actorUid);
		return $this->checkoutCore($actorUid, $itemId, $locationId, $personId, $qty, $dueAt, $lotCode, $conditionOut, $note);
	}

	/**
	 * Mobile Wave B: seat/device checkout. The route already asserted
	 * MobileGateService; location ACL is enforced inside
	 * MovementService::checkout (devices resolve via their grants).
	 */
	public function checkoutForSeat(
		string $actorUid,
		int $itemId,
		int $locationId,
		int $personId,
		int $qty,
		?int $dueAt,
		?string $lotCode,
		?string $conditionOut,
		?string $note,
	): array {
		return $this->checkoutCore($actorUid, $itemId, $locationId, $personId, $qty, $dueAt, $lotCode, $conditionOut, $note);
	}

	private function checkoutCore(
		string $actorUid,
		int $itemId,
		int $locationId,
		int $personId,
		int $qty,
		?int $dueAt,
		?string $lotCode,
		?string $conditionOut,
		?string $note,
	): array {
		$person = $this->findPersonOr404($personId);
		if (!$person->getActive() || $person->getAnonymized()) {
			throw new ValidationException('person_not_loanable', '', [
				['field' => 'personId', 'code' => 'person_not_loanable'],
			]);
		}
		$now = $this->clock->now();
		if ($dueAt !== null && ($dueAt <= 0 || $dueAt > 4_102_444_800)) {
			throw new ValidationException('validation_failed', '', [
				['field' => 'dueAt', 'code' => 'validation_failed'],
			]);
		}
		$cond = $conditionOut !== null ? strtolower(trim($conditionOut)) : 'ok';
		if (!in_array($cond, self::CONDITIONS_OUT, true)) {
			throw new ValidationException('validation_failed', '', [
				['field' => 'conditionOut', 'code' => 'validation_failed'],
			]);
		}
		$note = $this->normalizeNote($note, 'note');

		$result = $this->movements->checkout(
			$actorUid, $itemId, $locationId, $qty, $note, $lotCode,
			function () use ($itemId, $locationId, $personId, $qty, $lotCode, $dueAt, $cond, $note, $actorUid, $now): int {
				$loan = new Loan();
				$loan->setItemId($itemId);
				$loan->setPersonId($personId);
				$loan->setLocationId($locationId);
				$loan->setQty($qty);
				$loan->setLotCode($lotCode);
				$loan->setQtyReturned(0);
				$loan->setStatus(Loan::STATUS_OPEN);
				$loan->setDueAt($dueAt);
				$loan->setCheckedOutAt($now);
				$loan->setCheckedOutBy($actorUid);
				$loan->setConditionOut($cond);
				$loan->setNote($note);
				return $this->loans->insert($loan)->getId();
			},
		);
		$loan = $this->loans->findById($result['loanId']);
		return ['loan' => $loan, 'movement' => $result['movement']];
	}

	/**
	 * Office+ check-in: qty ≤ outstanding enforced inside the row-locked
	 * txn (uniform 'qty_exceeds_outstanding'), CAS makes concurrent
	 * returns single-winner (SPEC AC-7).
	 */
	public function checkin(
		string $actorUid,
		int $loanId,
		int $qty,
		?string $conditionIn,
		?string $note,
	): array {
		$this->access->requireOffice($actorUid);
		return $this->checkinCore($actorUid, $loanId, $qty, $conditionIn, $note);
	}

	/** Mobile Wave B: seat/device checkin — gate asserted by the route. */
	public function checkinForSeat(
		string $actorUid,
		int $loanId,
		int $qty,
		?string $conditionIn,
		?string $note,
	): array {
		return $this->checkinCore($actorUid, $loanId, $qty, $conditionIn, $note);
	}

	private function checkinCore(
		string $actorUid,
		int $loanId,
		int $qty,
		?string $conditionIn,
		?string $note,
	): array {
		$cond = $conditionIn !== null ? strtolower(trim($conditionIn)) : 'ok';
		if (!in_array($cond, self::CONDITIONS_IN, true)) {
			throw new ValidationException('validation_failed', '', [
				['field' => 'conditionIn', 'code' => 'validation_failed'],
			]);
		}
		$note = $this->normalizeNote($note, 'note');
		// Uniform 404 for callers without access to the loan's location.
		$peek = $this->findOr404($loanId);
		$this->assertLoanLocationAccess($actorUid, $peek);
		$now = $this->clock->now();

		$result = $this->movements->checkin(
			$actorUid, $peek, $qty, $note,
			function (int $appliedQty) use ($loanId, $cond, $note, $actorUid, $now): int {
				// FOR UPDATE read: surface field errors before the CAS write.
				$locked = $this->loans->findByIdForUpdate($loanId);
				if (!$locked->isOpen()) {
					throw new ValidationException('loan_not_open', '', [
						['field' => 'qty', 'code' => 'loan_not_open'],
					]);
				}
				if ($appliedQty > $locked->outstanding()) {
					throw new ValidationException('qty_exceeds_outstanding', '', [
						['field' => 'qty', 'code' => 'qty_exceeds_outstanding'],
					]);
				}
				$affected = $this->loans->tryApplyReturn($loanId, $appliedQty, $now, $actorUid);
				if ($affected === 1 && ($cond !== 'ok' || $note !== null)) {
					$updated = $this->loans->findById($loanId);
					$updated->setConditionIn($cond);
					if ($note !== null) {
						$updated->setDamageNote($note);
					}
					$this->loans->update($updated);
				}
				return $affected;
			},
		);
		return ['loan' => $this->loans->findById($loanId), 'movement' => $result['movement']];
	}

	/** Terminal 'lost' state — office decides a loan will not come back. */
	public function markLost(string $actorUid, int $loanId, ?string $note): Loan
	{
		$this->access->requireOffice($actorUid);
		$loan = $this->findOr404($loanId);
		$this->assertLoanLocationAccess($actorUid, $loan);
		if (!$loan->isOpen()) {
			throw new ValidationException('loan_not_open', '', [
				['field' => 'status', 'code' => 'loan_not_open'],
			]);
		}
		$note = $this->normalizeNote($note, 'damageNote');
		$loan->setStatus(Loan::STATUS_LOST);
		if ($note !== null) {
			$loan->setDamageNote($note);
		}
		return $this->loans->update($loan);
	}

	public function findOr404(int $loanId): Loan
	{
		try {
			return $this->loans->findById($loanId);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			throw new NotFoundException('unknown_loan');
		}
	}

	/** @return array{loans:list<array>, counts:array{open:int,overdue:int,dueToday:int}} */
	public function overview(string $actorUid, ?bool $overdueOnly = false, int $limit = 50, int $offset = 0): array
	{
		$this->access->requireOffice($actorUid);
		$visible = $this->locationAcl->visibleLocationIds($actorUid);
		$now = $this->clock->now();
		$loans = $this->loans->openScoped($visible, $now, (bool)$overdueOnly, min($limit, 200), $offset);
		return [
			'loans' => array_map(fn (Loan $l): array => $this->decorate($l, $now), $loans),
			'counts' => $this->loans->overviewCounts($visible, $now),
		];
	}

	/** @return list<array> open/partial loans against an item (item detail "Ausgeliehen") */
	public function openForItem(string $actorUid, int $itemId): array
	{
		$this->access->requireOffice($actorUid);
		try {
			$this->items->findById($itemId);
		} catch (\Throwable) {
			throw new NotFoundException('unknown_item');
		}
		$now = $this->clock->now();
		return array_map(fn (Loan $l): array => $this->decorate($l, $now), $this->loans->openForItem($itemId));
	}

	/**
	 * Mobile Wave B: open loans on an item, location-ACL scoped — devices
	 * only see loans whose location is covered by their grants.
	 * @return list<array>
	 */
	public function openForItemForSeat(string $actorUid, int $itemId): array
	{
		try {
			$this->items->findById($itemId);
		} catch (\Throwable) {
			throw new NotFoundException('unknown_item');
		}
		$visible = $this->locationAcl->visibleLocationIds($actorUid);
		$now = $this->clock->now();
		return array_map(
			fn (Loan $l): array => $this->decorate($l, $now),
			array_values(array_filter(
				$this->loans->openForItem($itemId),
				fn (Loan $l): bool => $visible === null || in_array($l->getLocationId(), $visible, true),
			)),
		);
	}

	/** @return array{open:list<array>, history:list<array>} loans for a person detail page */
	public function forPerson(string $actorUid, int $personId, int $limit = 50, int $offset = 0): array
	{
		$this->access->requireOffice($actorUid);
		$this->findPersonOr404($personId);
		$now = $this->clock->now();
		return [
			'open' => array_map(fn (Loan $l): array => $this->decorate($l, $now), $this->loans->openForPerson($personId, $limit, $offset)),
			'history' => array_map(fn (Loan $l): array => $this->decorate($l, $now), $this->loans->historyForPerson($personId, $limit, $offset)),
		];
	}

	private function decorate(Loan $loan, int $now): array
	{
		$person = null;
		try {
			$person = $this->persons->findById($loan->getPersonId());
		} catch (\Throwable) {
		}
		$itemName = null;
		try {
			$itemName = $this->items->findById($loan->getItemId())->getName();
		} catch (\Throwable) {
		}
		$locLabel = null;
		try {
			$loc = $this->locations->findById($loan->getLocationId());
			$locLabel = method_exists($loc, 'getPathLabel') && $loc->getPathLabel() ? $loc->getPathLabel() : $loc->getName();
		} catch (\Throwable) {
		}
		return $loan->toApi($person, $itemName, $locLabel, $now);
	}

	private function findPersonOr404(int $personId): Person
	{
		try {
			return $this->persons->findById($personId);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			throw new NotFoundException('unknown_person');
		}
	}

	private function assertLoanLocationAccess(string $actorUid, Loan $loan): void
	{
		if (!$this->locationAcl->canAccessLocation($actorUid, $loan->getLocationId())) {
			throw new NotFoundException('unknown_loan');
		}
	}

	private function normalizeNote(?string $note, string $field): ?string
	{
		if ($note === null) {
			return null;
		}
		$note = trim($note);
		if ($note === '') {
			return null;
		}
		if (mb_strlen($note) > 512) {
			throw new ValidationException('validation_failed', '', [
				['field' => $field, 'code' => 'validation_failed'],
			]);
		}
		return $note;
	}
}
