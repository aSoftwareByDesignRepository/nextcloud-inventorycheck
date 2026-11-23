<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Db\Loan;
use OCA\InventoryCheck\Db\LoanMapper;
use OCA\InventoryCheck\Db\PersonMapper;
use OCA\InventoryCheck\Exception\ConflictException;
use OCA\InventoryCheck\Exception\InsufficientStockException;
use OCA\InventoryCheck\Exception\PermissionDeniedException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\LoanDueNotifyService;
use OCA\InventoryCheck\Service\LoanService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\MovementService;
use OCA\InventoryCheck\Service\PersonService;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Server;
use Test\TestCase;

/**
 * IV-CUSTODY-LENDING Wave A — checkout/checkin ledger lifecycle, CAS
 * single-winner returns (AC-7), non-negative custody floor (NN-01),
 * person delete-block + anonymisation (AC-13), due-scan dedupe (AC-11).
 *
 * @group DB
 */
final class CustodyLendingIntegrationTest extends TestCase
{
	private string $uid = 'admin';
	private LocationService $locations;
	private ItemService $items;
	private MovementService $movements;
	private LoanService $loans;
	private PersonService $persons;
	private LoanDueNotifyService $due;
	private IDBConnection $db;
	private LoanMapper $loanMapper;
	private PersonMapper $personMapper;

	protected function setUp(): void
	{
		parent::setUp();
		$c = (new Application())->getContainer();
		$this->locations = $c->get(LocationService::class);
		$this->items = $c->get(ItemService::class);
		$this->movements = $c->get(MovementService::class);
		$this->loans = $c->get(LoanService::class);
		$this->persons = $c->get(PersonService::class);
		$this->due = $c->get(LoanDueNotifyService::class);
		$this->db = Server::get(IDBConnection::class);
		$this->loanMapper = $c->get(LoanMapper::class);
		$this->personMapper = $c->get(PersonMapper::class);
	}

	private function fixture(string $suffix): array
	{
		$loc = $this->locations->create($this->uid, [
			'code' => 'CL-L-' . $suffix, 'name' => 'Custody Loc', 'kind' => 'site',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => 'CL-I-' . $suffix, 'name' => 'Drill', 'uom' => 'pcs',
		]);
		$this->movements->receive($this->uid, (int)$item['id'], (int)$loc['id'], 5, null);
		$person = $this->persons->create($this->uid, 'Mia ' . $suffix, 'M-' . $suffix, null, null);
		return [$loc, $item, $person];
	}

	public function testCheckoutDecrementsAndCheckinRestores(): void
	{
		$suffix = bin2hex(random_bytes(3));
		[$loc, $item, $person] = $this->fixture($suffix);
		$due = time() + 7 * 86400;

		$out = $this->loans->checkout($this->uid, (int)$item['id'], (int)$loc['id'], (int)$person->getId(), 2, $due, null, 'ok', null);
		$loan = $out['loan'];
		$this->assertSame(Loan::STATUS_OPEN, $loan->getStatus());
		$this->assertSame('checkout', $out['movement']->getKind());
		$this->assertSame(MovementService::REF_TYPE_LOAN, $out['movement']->getRefType());
		$this->assertSame($loan->getId(), $out['movement']->getRefId());
		$this->assertSame(-2, $out['movement']->getQtyDelta());
		$this->assertSame(3, $out['movement']->getQtyAfter());

		// Partial then full return — lifecycle open → partial → returned.
		$r1 = $this->loans->checkin($this->uid, $loan->getId(), 1, 'ok', null);
		$this->assertSame(Loan::STATUS_PARTIAL, $r1['loan']->getStatus());
		$this->assertSame(1, $r1['loan']->getQtyReturned());
		$this->assertSame(1, $r1['movement']->getQtyDelta());
		$this->assertSame(4, $r1['movement']->getQtyAfter());

		$r2 = $this->loans->checkin($this->uid, $loan->getId(), 1, 'damaged', 'dented case');
		$this->assertSame(Loan::STATUS_RETURNED, $r2['loan']->getStatus());
		$this->assertSame('damaged', $r2['loan']->getConditionIn());
		$this->assertSame('dented case', $r2['loan']->getDamageNote());
		$this->assertNotNull($r2['loan']->getReturnedAt());
	}

	public function testCheckoutNeverGoesNegativeEvenWhenAllowed(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$config = Server::get(\OCP\IConfig::class);
		$config->setAppValue(Application::APP_ID, 'allow_negative_stock', '1');
		try {
			[$loc, $item, $person] = $this->fixture($suffix);
			$this->expectException(InsufficientStockException::class);
			$this->loans->checkout($this->uid, (int)$item['id'], (int)$loc['id'], (int)$person->getId(), 6, null, null, null, null);
		} finally {
			$config->deleteAppValue(Application::APP_ID, 'allow_negative_stock');
		}
	}

	public function testOverReturnRejectedWithFieldError(): void
	{
		$suffix = bin2hex(random_bytes(3));
		[$loc, $item, $person] = $this->fixture($suffix);
		$out = $this->loans->checkout($this->uid, (int)$item['id'], (int)$loc['id'], (int)$person->getId(), 2, null, null, null, null);
		try {
			$this->loans->checkin($this->uid, $out['loan']->getId(), 3, 'ok', null);
			$this->fail('over-return should reject');
		} catch (ValidationException $e) {
			$this->assertSame('qty_exceeds_outstanding', $e->getMessage());
		}
		// The failed attempt must not have touched qty_returned.
		$this->assertSame(0, $this->loanMapper->findById($out['loan']->getId())->getQtyReturned());
	}

	public function testCheckinOnClosedLoanRejected(): void
	{
		$suffix = bin2hex(random_bytes(3));
		[$loc, $item, $person] = $this->fixture($suffix);
		$out = $this->loans->checkout($this->uid, (int)$item['id'], (int)$loc['id'], (int)$person->getId(), 2, null, null, null, null);
		$this->loans->checkin($this->uid, $out['loan']->getId(), 2, 'ok', null);
		try {
			$this->loans->checkin($this->uid, $out['loan']->getId(), 1, 'ok', null);
			$this->fail('closed loan should reject');
		} catch (ValidationException $e) {
			$this->assertSame('loan_not_open', $e->getMessage());
		}
	}

	public function testCasSingleWinnerOnConcurrentCheckin(): void
	{
		$suffix = bin2hex(random_bytes(3));
		[$loc, $item, $person] = $this->fixture($suffix);
		$out = $this->loans->checkout($this->uid, (int)$item['id'], (int)$loc['id'], (int)$person->getId(), 2, null, null, null, null);
		$loanId = $out['loan']->getId();

		// Simulate the CAS losing side directly at the mapper: a return that
		// overshoots outstanding must affect 0 rows (SPEC AC-7 race loser).
		$affected = $this->loanMapper->tryApplyReturn($loanId, 3, time(), $this->uid);
		$this->assertSame(0, $affected);
		$affected = $this->loanMapper->tryApplyReturn($loanId, 2, time(), $this->uid);
		$this->assertSame(1, $affected);
		$loan = $this->loanMapper->findById($loanId);
		$this->assertSame(Loan::STATUS_RETURNED, $loan->getStatus());
		// Second concurrent CAS on the now-returned row: also a loser.
		$this->assertSame(0, $this->loanMapper->tryApplyReturn($loanId, 1, time(), $this->uid));
	}

	public function testPersonDeleteBlockedByOpenLoanAnonymizeAfterReturn(): void
	{
		$suffix = bin2hex(random_bytes(3));
		[$loc, $item, $person] = $this->fixture($suffix);
		$out = $this->loans->checkout($this->uid, (int)$item['id'], (int)$loc['id'], (int)$person->getId(), 1, null, null, null, null);

		try {
			$this->persons->anonymize($this->uid, (int)$person->getId());
			$this->fail('open loan must block anonymize');
		} catch (ConflictException $e) {
			$this->assertSame('person_has_open_loans', $e->getMessage());
		}

		$this->loans->checkin($this->uid, $out['loan']->getId(), 1, 'ok', null);
		$anon = $this->persons->anonymize($this->uid, (int)$person->getId());
		$this->assertSame(1, $anon->getAnonymized());
		$this->assertSame('deleted person #' . $person->getId(), $anon->getDisplayName());
		$this->assertNull($anon->getMemberNo());
		$this->assertNull($anon->getNcUserId());
		// Loan history survives — the row still points at the person.
		$this->assertCount(1, $this->loanMapper->historyForPerson((int)$person->getId()));
	}

	public function testCheckoutRequiresOffice(): void
	{
		$users = Server::get(IUserManager::class);
		$uid = 'iv-cl-field' . bin2hex(random_bytes(3));
		if ($users->get($uid) === null) {
			$users->createUser($uid, bin2hex(random_bytes(8)));
		}
		$suffix = bin2hex(random_bytes(3));
		[$loc, $item, $person] = $this->fixture($suffix);
		$this->expectException(PermissionDeniedException::class);
		$this->loans->checkout($uid, (int)$item['id'], (int)$loc['id'], (int)$person->getId(), 1, null, null, null, null);
	}

	public function testDueScanDedupesPerDay(): void
	{
		$suffix = bin2hex(random_bytes(3));
		[$loc, $item, $person] = $this->fixture($suffix);
		$past = time() - 86400;
		$this->loans->checkout($this->uid, (int)$item['id'], (int)$loc['id'], (int)$person->getId(), 1, $past, null, null, null);

		$first = $this->due->run();
		$this->assertGreaterThanOrEqual(1, $first);
		// Second run same day: every recipient deduped → zero new sends.
		$this->assertSame(0, $this->due->run());
	}

	public function testOverviewCountsAndItemLoans(): void
	{
		$suffix = bin2hex(random_bytes(3));
		[$loc, $item, $person] = $this->fixture($suffix);
		$this->loans->checkout($this->uid, (int)$item['id'], (int)$loc['id'], (int)$person->getId(), 1, time() - 3600, null, null, null);
		$this->loans->checkout($this->uid, (int)$item['id'], (int)$loc['id'], (int)$person->getId(), 1, time() + 86400, null, null, null);

		$open = $this->loans->openForItem($this->uid, (int)$item['id']);
		$this->assertCount(2, $open);
		$overdue = array_filter($open, static fn (array $l): bool => $l['overdue'] === true);
		$this->assertCount(1, $overdue);

		$counts = $this->loans->overview($this->uid)['counts'];
		$this->assertGreaterThanOrEqual(2, $counts['open']);
		$this->assertGreaterThanOrEqual(1, $counts['overdue']);
	}

	public function testPersonSearchMergesNcUsers(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$person = $this->persons->create($this->uid, 'Zed Find ' . $suffix, 'ZF-' . $suffix, null, null);
		$hit = $this->persons->search($this->uid, 'Zed Find ' . $suffix);
		$this->assertFalse($hit['truncated']);
		$persons = array_filter($hit['results'], static fn (array $r): bool => $r['type'] === 'person');
		$this->assertNotEmpty($persons);
		// A fresh NC user with a unique display name surfaces as ncUser.
		$users = Server::get(IUserManager::class);
		$uid = 'iv-cl-ncu' . $suffix;
		$u = $users->get($uid) ?? $users->createUser($uid, bin2hex(random_bytes(8)));
		$u->setDisplayName('Ncu Probe ' . $suffix);
		$ncHit = $this->persons->search($this->uid, 'Ncu Probe ' . $suffix);
		$nc = array_filter($ncHit['results'], static fn (array $r): bool => $r['type'] === 'ncUser' && $r['ncUser']['uid'] === $uid);
		$this->assertNotEmpty($nc);
	}
}
