<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Db\Loan;
use OCA\InventoryCheck\Db\LoanMapper;
use OCA\InventoryCheck\Db\PersonMapper;
use OCA\InventoryCheck\Db\ScanDevice;
use OCA\InventoryCheck\Db\ScanDeviceMapper;
use OCA\InventoryCheck\Controller\MobileController;
use OCA\InventoryCheck\Exception\ConflictException;
use OCA\InventoryCheck\Exception\InsufficientStockException;
use OCA\InventoryCheck\Exception\MobileGateException;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\PermissionDeniedException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\LoanDueNotifyService;
use OCA\InventoryCheck\Service\LoanEvidenceService;
use OCA\InventoryCheck\Service\LoanService;
use OCA\InventoryCheck\Service\LocationAclService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\MovementService;
use OCA\InventoryCheck\Service\PersonService;
use OCA\InventoryCheck\Service\ScanIdempotencyService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
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

		// Recipients come from the configured office/admin lists — pin one so
		// the scan is independent of whatever access config the dev DB has.
		$access = (new Application())->getContainer()->get(AccessControlService::class);
		$before = $access->getJsonIdList(AccessControlService::KEY_APP_ADMINS);
		$access->setJsonIdList(AccessControlService::KEY_APP_ADMINS, [$this->uid]);
		try {
			$first = $this->due->run();
			$this->assertGreaterThanOrEqual(1, $first);
			// Second run same day: every recipient deduped → zero new sends.
			$this->assertSame(0, $this->due->run());
		} finally {
			$access->setJsonIdList(AccessControlService::KEY_APP_ADMINS, $before);
		}
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

	// ---- Wave B: seat-gated mobile loans + evidence ----

	private function freshUser(): string
	{
		$users = Server::get(IUserManager::class);
		$uid = 'iv-cl-seat' . bin2hex(random_bytes(4));
		return $users->get($uid) === null ? ($users->createUser($uid, bin2hex(random_bytes(8)))?->getUID() ?? $uid) : $uid;
	}

	private function makeDevice(string $suffix): int
	{
		$devices = (new Application())->getContainer()->get(ScanDeviceMapper::class);
		$now = time();
		$d = new ScanDevice();
		$d->setLabel('CL scanner ' . $suffix);
		$d->setPairCodeHash(null);
		$d->setPairCodeExpires(null);
		$d->setTokenHash(hash('sha256', 'iv-cl-token-' . $suffix));
		$d->setPairedAt($now);
		$d->setLastSeenAt($now);
		$d->setActive(true);
		$d->setCreatedAt($now);
		$d->setCreatedBy($this->uid);
		return (int)$devices->insert($d)->getId();
	}

	public function testSeatCheckoutCheckinBypassOfficeButKeepRules(): void
	{
		$uid = $this->freshUser();
		$suffix = bin2hex(random_bytes(3));
		[$loc, $item, $person] = $this->fixture($suffix);
		// Non-office actor: checkout() would 403; the seat variant must not.
		$out = $this->loans->checkoutForSeat($uid, (int)$item['id'], (int)$loc['id'], (int)$person->getId(), 2, time() + 86400, null, 'ok', 'seat');
		$loanId = (int)$out['loan']->getId();
		$this->assertGreaterThan(0, $loanId);
		// Business rules still apply on the seat path.
		try {
			$this->loans->checkinForSeat($uid, $loanId, 9, 'ok', null);
			$this->fail('over-return must fail');
		} catch (ValidationException $e) {
			$this->assertNotEmpty($e->getDetails());
		}
		$this->loans->checkinForSeat($uid, $loanId, 2, 'damaged', 'back');
		$this->assertSame('returned', $this->loanMapper->findById($loanId)->getStatus());
	}

	public function testSeatCheckoutDeviceHonorsLocationGrants(): void
	{
		$config = Server::get(IConfig::class);
		$acl = (new Application())->getContainer()->get(LocationAclService::class);
		$config->setAppValue(Application::APP_ID, LocationAclService::KEY_ENABLED, '1');
		$suffix = bin2hex(random_bytes(3));
		[$loc, $item, $person] = $this->fixture($suffix);
		[$loc2] = [$this->locations->create($this->uid, ['code' => 'CL-L2-' . $suffix, 'name' => 'Other', 'kind' => 'site'])];
		$idA = $this->makeDevice($suffix . 'a');
		$idB = $this->makeDevice($suffix . 'b');
		$granted = 'device:' . $idA;
		$denied = 'device:' . $idB;
		$acl->setForSubject(LocationAclService::TYPE_DEVICE, (string)$idA, [(int)$loc['id']]);
		$acl->setForSubject(LocationAclService::TYPE_DEVICE, (string)$idB, [(int)$loc2['id']]);
		try {
			$this->loans->checkoutForSeat($granted, (int)$item['id'], (int)$loc['id'], (int)$person->getId(), 1, null, null, 'ok', null);
			// Uniform 404 — a denied device must not learn the location exists.
			$this->expectException(NotFoundException::class);
			$this->loans->checkoutForSeat($denied, (int)$item['id'], (int)$loc['id'], (int)$person->getId(), 1, null, null, 'ok', null);
		} finally {
			$config->setAppValue(Application::APP_ID, LocationAclService::KEY_ENABLED, '0');
			$acl->purgeDevice($idA);
			$acl->purgeDevice($idB);
		}
	}

	public function testSearchForSeatNeverReturnsNcUsers(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$this->persons->create($this->uid, 'Seat Hit ' . $suffix, 'SH-' . $suffix, null, null);
		$users = Server::get(IUserManager::class);
		$uid = 'iv-cl-ncs' . $suffix;
		$u = $users->get($uid) ?? $users->createUser($uid, bin2hex(random_bytes(8)));
		$u->setDisplayName('Seat Hit ' . $suffix);
		$hit = $this->persons->searchForSeat('device:5', 'Seat Hit ' . $suffix);
		$this->assertNotEmpty(array_filter($hit['results'], static fn (array $r): bool => $r['type'] === 'person'));
		$this->assertEmpty(array_filter($hit['results'], static fn (array $r): bool => $r['type'] === 'ncUser'), 'devices must not enumerate NC accounts');
	}

	public function testOpenForItemForSeatScopesByDeviceGrants(): void
	{
		$config = Server::get(IConfig::class);
		$acl = (new Application())->getContainer()->get(LocationAclService::class);
		$config->setAppValue(Application::APP_ID, LocationAclService::KEY_ENABLED, '1');
		$suffix = bin2hex(random_bytes(3));
		[$loc, $item, $person] = $this->fixture($suffix);
		$this->loans->checkoutForSeat($this->uid, (int)$item['id'], (int)$loc['id'], (int)$person->getId(), 1, null, null, 'ok', null);
		$idC = $this->makeDevice($suffix . 'c');
		$idD = $this->makeDevice($suffix . 'd');
		$acl->setForSubject(LocationAclService::TYPE_DEVICE, (string)$idC, [(int)$loc['id']]);
		$acl->setForSubject(LocationAclService::TYPE_DEVICE, (string)$idD, []);
		try {
			$this->assertCount(1, $this->loans->openForItemForSeat('device:' . $idC, (int)$item['id']));
			$this->assertSame([], $this->loans->openForItemForSeat('device:' . $idD, (int)$item['id']), 'no grants → no visibility');
		} finally {
			$config->setAppValue(Application::APP_ID, LocationAclService::KEY_ENABLED, '0');
			$acl->purgeDevice($idC);
			$acl->purgeDevice($idD);
		}
	}

	/**
	 * AC-19: two identical clientRequestIds → exactly one loan row. The
	 * replay path is the controller's own idemClaimOrReplay, invoked via
	 * Closure::bind so the test exercises the real wiring without mocking
	 * the device-auth request.
	 */
	public function testLoanCheckoutReplayDoesNotDoubleBook(): void
	{
		$suffix = bin2hex(random_bytes(3));
		[$loc, $item, $person] = $this->fixture($suffix);
		$c = (new Application())->getContainer();
		$idem = $c->get(ScanIdempotencyService::class);
		$controller = $c->get(MobileController::class);
		$claimOrReplay = \Closure::bind(
			fn (string $actor, string $claimId, string $hash) => $this->idemClaimOrReplay($actor, $claimId, $hash),
			$controller,
			MobileController::class,
		);

		$actor = 'device:ac19-' . $suffix;
		$params = [
			'itemId' => (int)$item['id'],
			'locationId' => (int)$loc['id'],
			'personId' => (int)$person->getId(),
			'qty' => 2,
		];
		$claimId = 'loan:' . $idem->normalizeClientRequestId('ac19-' . $suffix);
		$hash = $idem->fingerprintFromParams($params);

		// First attempt: claim succeeds, the real checkout commits, response
		// is stored — mirrors loansCheckout() step for step.
		$this->assertNull($claimOrReplay($actor, $claimId, $hash));
		$this->loans->checkoutForSeat($actor, (int)$item['id'], (int)$loc['id'], (int)$person->getId(), 2, null, null, 'ok', null);
		$stored = ['loan' => ['id' => 42]];
		$idem->complete($actor, $claimId, $stored);

		// Retry with the same key + payload: replayed response, no second loan.
		$replay = $claimOrReplay($actor, $claimId, $hash);
		$this->assertInstanceOf(JSONResponse::class, $replay);
		$this->assertSame($stored, $replay->getData());
		$this->assertCount(1, $this->loanMapper->openForPerson((int)$person->getId()));

		// Same key, different payload → hard mismatch, never a silent re-book.
		$otherHash = $idem->fingerprintFromParams(array_merge($params, ['qty' => 3]));
		try {
			$claimOrReplay($actor, $claimId, $otherHash);
			$this->fail('payload mismatch must reject');
		} catch (MobileGateException $e) {
			$this->assertSame('idempotency_payload_mismatch', $e->getMessage());
		}

		// In-flight twin (claimed, never completed) → in_flight, not a dup loan.
		$inflightId = 'loan:' . $idem->normalizeClientRequestId('ac19-b-' . $suffix);
		$this->assertTrue($idem->tryClaim($actor, $inflightId, $hash));
		try {
			$claimOrReplay($actor, $inflightId, $hash);
			$this->fail('pending twin must report in-flight');
		} catch (MobileGateException $e) {
			$this->assertSame('idempotency_in_flight', $e->getMessage());
		}
		// Released claim frees the key for a genuine retry.
		$idem->release($actor, $inflightId);
		$this->assertNull($claimOrReplay($actor, $inflightId, $hash));

		$this->assertCount(1, $this->loanMapper->openForPerson((int)$person->getId()), 'exactly one loan for two identical request keys');
	}

	public function testSignatureStoreReadAndDtoFlags(): void
	{
		$evidence = (new Application())->getContainer()->get(LoanEvidenceService::class);
		$suffix = bin2hex(random_bytes(3));
		[$loc, $item, $person] = $this->fixture($suffix);
		$out = $this->loans->checkout($this->uid, (int)$item['id'], (int)$loc['id'], (int)$person->getId(), 1, null, null, 'ok', null);
		$loanId = (int)$out['loan']->getId();
		$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAABgAAAAYCAYAAADgdz34AAAAIklEQVR4nGP4T2PAMGrBqAWjFoxaMGrBqAWjFoxaMDQsAACAo/d5ePaf+gAAAABJRU5ErkJggg==');
		$loan = $evidence->storeSignature($this->uid, $loanId, base64_encode($png));
		$this->assertNotNull($loan->getSignaturePath());
		$read = $evidence->read($this->uid, $loanId, 'signature');
		$this->assertSame($png, $read['content']);
		$this->assertSame('image/png', $read['mime']);
		$api = $loan->toApi();
		$this->assertTrue($api['hasSignature']);
		$this->assertArrayNotHasKey('signaturePath', $api, 'raw path must never leak');
		$fresh = $this->loanMapper->findById($loanId);
		$this->assertNotNull($fresh->getSignaturePath());
	}
}
