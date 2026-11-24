<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Db\Loan;
use OCA\InventoryCheck\Db\LoanMapper;
use OCA\InventoryCheck\Db\MovementMapper;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\LoanService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\MovementService;
use OCA\InventoryCheck\Service\PersonService;
use Test\TestCase;

/**
 * MF-01 wave-close gate (SPEC AC-29): balance conservation must be
 * provable from movement rows alone — the loan row is a view over the
 * ledger, never the ledger itself.
 *
 * For every loan:  checkout == Σ checkins + losses + outstanding
 *   open/partial → losses = 0,             outstanding = derived
 *   returned     → losses = 0,             outstanding = 0
 *   lost         → losses = derived (units that never came back),
 *                  outstanding = 0
 *
 * Plus the global leg: the location balance must equal the signed sum of
 * ALL movement deltas for the pair — custody kinds included.
 *
 * @group DB
 */
final class CustodyConservationIntegrationTest extends TestCase
{
	private string $uid = 'admin';
	private LocationService $locations;
	private ItemService $items;
	private MovementService $movements;
	private LoanService $loans;
	private PersonService $persons;
	private LoanMapper $loanMapper;
	private MovementMapper $movementMapper;
	private BalanceMapper $balanceMapper;

	protected function setUp(): void
	{
		parent::setUp();
		$c = (new Application())->getContainer();
		$this->locations = $c->get(LocationService::class);
		$this->items = $c->get(ItemService::class);
		$this->movements = $c->get(MovementService::class);
		$this->loans = $c->get(LoanService::class);
		$this->persons = $c->get(PersonService::class);
		$this->loanMapper = $c->get(LoanMapper::class);
		$this->movementMapper = $c->get(MovementMapper::class);
		$this->balanceMapper = $c->get(BalanceMapper::class);
	}

	public function testConservationProvableFromMovementsAlone(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$loc = $this->locations->create($this->uid, [
			'code' => 'CC-L-' . $suffix, 'name' => 'Conservation Loc', 'kind' => 'site',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => 'CC-I-' . $suffix, 'name' => 'Costume', 'uom' => 'pcs',
		]);
		$this->movements->receive($this->uid, (int)$item['id'], (int)$loc['id'], 20, null);
		$person = $this->persons->create($this->uid, 'Pat ' . $suffix, 'P-' . $suffix, null, null);
		$personId = (int)$person->getId();
		$itemId = (int)$item['id'];
		$locId = (int)$loc['id'];

		// One loan per lifecycle state so every branch of the invariant runs.
		$open = $this->loans->checkout($this->uid, $itemId, $locId, $personId, 3, null, null, 'ok', null)['loan'];

		$partial = $this->loans->checkout($this->uid, $itemId, $locId, $personId, 4, null, null, 'ok', null)['loan'];
		$this->loans->checkin($this->uid, (int)$partial->getId(), 1, 'ok', null);

		$returned = $this->loans->checkout($this->uid, $itemId, $locId, $personId, 2, null, null, 'ok', null)['loan'];
		$this->loans->checkin($this->uid, (int)$returned->getId(), 2, 'ok', null);

		$lostAfterPartial = $this->loans->checkout($this->uid, $itemId, $locId, $personId, 5, null, null, 'ok', null)['loan'];
		$this->loans->checkin($this->uid, (int)$lostAfterPartial->getId(), 2, 'ok', null);
		$this->loans->markLost($this->uid, (int)$lostAfterPartial->getId(), 'gone at the parade');

		$lostWhole = $this->loans->checkout($this->uid, $itemId, $locId, $personId, 1, null, null, 'ok', null)['loan'];
		$this->loans->markLost($this->uid, (int)$lostWhole->getId(), null);

		$loans = [
			$this->loanMapper->findById((int)$open->getId()),
			$this->loanMapper->findById((int)$partial->getId()),
			$this->loanMapper->findById((int)$returned->getId()),
			$this->loanMapper->findById((int)$lostAfterPartial->getId()),
			$this->loanMapper->findById((int)$lostWhole->getId()),
		];
		$this->assertSame(
			[Loan::STATUS_OPEN, Loan::STATUS_PARTIAL, Loan::STATUS_RETURNED, Loan::STATUS_LOST, Loan::STATUS_LOST],
			array_map(static fn (Loan $l): string => $l->getStatus(), $loans),
		);

		foreach ($loans as $loan) {
			$this->assertLoanInvariant($loan);
		}

		// Global leg: the pair balance must equal the signed sum of every
		// movement delta — custody kinds are real ledger entries.
		$deltaSum = 0;
		foreach ($this->movementLegsForPair($itemId, $locId) as $row) {
			$deltaSum += (int)$row['qty_delta'];
		}
		$balance = $this->balanceMapper->findPair($itemId, $locId);
		$this->assertNotNull($balance);
		// receive 20 − (3+4+2+5+1 checked out) + (1+2+2 checked in) = 10.
		$this->assertSame(10, $balance->getQty());
		$this->assertSame($deltaSum, $balance->getQty(), 'balance must be reconstructible from movement deltas alone');
	}

	private function assertLoanInvariant(Loan $loan): void
	{
		$legs = $this->movementMapper->findByLoanRef((int)$loan->getId());
		$this->assertNotEmpty($legs, 'a loan without movement legs is a shadow ledger');

		$checkedOut = 0;
		$checkedIn = 0;
		foreach ($legs as $leg) {
			$this->assertSame($loan->getItemId(), $leg->getItemId());
			$this->assertSame($loan->getLocationId(), $leg->getLocationId());
			match ($leg->getKind()) {
				'checkout' => $checkedOut += -$leg->getQtyDelta(),
				'checkin' => $checkedIn += $leg->getQtyDelta(),
				default => $this->fail('unexpected movement kind on a loan ref: ' . $leg->getKind()),
			};
		}

		// The loan row must be a faithful view over its legs.
		$this->assertSame($loan->getQty(), $checkedOut, 'checkout legs != loan.qty');
		$this->assertSame($loan->getQtyReturned(), $checkedIn, 'checkin legs != loan.qty_returned');
		$this->assertLessThanOrEqual($checkedOut, $checkedIn, 'over-return');

		$derivedOutstanding = $checkedOut - $checkedIn;
		[$losses, $outstanding] = match ($loan->getStatus()) {
			Loan::STATUS_LOST => [$derivedOutstanding, 0],
			Loan::STATUS_RETURNED => [0, 0],
			default => [0, $derivedOutstanding],
		};
		if ($loan->getStatus() === Loan::STATUS_RETURNED) {
			$this->assertSame(0, $derivedOutstanding, 'returned loan with unreturned units');
		} else {
			$this->assertSame($loan->outstanding(), $derivedOutstanding, 'loan-row outstanding diverges from movements');
		}

		// AC-29: checkout == Σ checkins + qty_lost_adjustments + outstanding.
		$this->assertSame(
			$checkedOut,
			$checkedIn + $losses + $outstanding,
			'AC-29 conservation violated for loan ' . $loan->getId(),
		);
	}

	/** @return list<array{qty_delta:int}> raw rows — item/location legs across all kinds */
	private function movementLegsForPair(int $itemId, int $locationId): array
	{
		$qb = \OCP\Server::get(\OCP\IDBConnection::class)->getQueryBuilder();
		$qb->select('qty_delta')
			->from(MovementMapper::TABLE)
			->where($qb->expr()->eq('item_id', $qb->createNamedParameter($itemId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('location_id', $qb->createNamedParameter($locationId, \PDO::PARAM_INT)));
		return $qb->executeQuery()->fetchAll();
	}
}
