<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Db\LoanRequestMapper;
use OCA\InventoryCheck\Db\ReservationMapper;
use OCA\InventoryCheck\Exception\ConflictException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\LoanRequestService;
use OCA\InventoryCheck\Service\LoanService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\MemberTokenService;
use OCA\InventoryCheck\Service\MovementService;
use OCA\InventoryCheck\Service\PersonService;
use OCA\InventoryCheck\Service\ReservationService;
use OCP\AppFramework\Db\DoesNotExistException;
use Test\TestCase;

/**
 * F2 Wave C — requests/reservations/member-tokens lifecycle:
 * token issue→resolve→revoke, request cap + CAS approve→loan one-tx,
 * reservation convert CAS + availability math + expiry sweep.
 *
 * @group DB
 */
final class WaveCPortalIntegrationTest extends TestCase
{
	private string $uid = 'admin';
	private LocationService $locations;
	private ItemService $items;
	private MovementService $movements;
	private LoanService $loans;
	private PersonService $persons;
	private MemberTokenService $tokens;
	private LoanRequestService $requests;
	private ReservationService $reservations;
	private LoanRequestMapper $requestMapper;
	private ReservationMapper $reservationMapper;

	protected function setUp(): void
	{
		parent::setUp();
		$c = (new Application())->getContainer();
		$this->locations = $c->get(LocationService::class);
		$this->items = $c->get(ItemService::class);
		$this->movements = $c->get(MovementService::class);
		$this->loans = $c->get(LoanService::class);
		$this->persons = $c->get(PersonService::class);
		$this->tokens = $c->get(MemberTokenService::class);
		$this->requests = $c->get(LoanRequestService::class);
		$this->reservations = $c->get(ReservationService::class);
		$this->requestMapper = $c->get(LoanRequestMapper::class);
		$this->reservationMapper = $c->get(ReservationMapper::class);
	}

	private function fixture(string $suffix): array
	{
		$loc = $this->locations->create($this->uid, [
			'code' => 'WC-L-' . $suffix, 'name' => 'Portal Loc', 'kind' => 'site',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => 'WC-I-' . $suffix, 'name' => 'Trident', 'uom' => 'pcs',
		]);
		$this->movements->receive($this->uid, (int)$item['id'], (int)$loc['id'], 5, null);
		$person = $this->persons->create($this->uid, 'Wanda ' . $suffix, 'W-' . $suffix, null, null);
		return [$loc, $item, $person];
	}

	public function testTokenIssueResolveRevoke(): void
	{
		[, , $person] = $this->fixture(bin2hex(random_bytes(3)));
		$out = $this->tokens->issue($this->uid, (int)$person->getId());
		$this->assertStringStartsWith('ivm_', $out['token']);
		// sha256 only — raw token never persists (findable only via resolve).
		$resolved = $this->tokens->resolve($out['token']);
		$this->assertNotNull($resolved);
		$this->assertSame((int)$person->getId(), $resolved[0]->getId());
		// Rotate: old link dies immediately.
		$out2 = $this->tokens->issue($this->uid, (int)$person->getId());
		$this->assertNull($this->tokens->resolve($out['token']));
		$this->assertNotNull($this->tokens->resolve($out2['token']));
		// Revoke → uniform null.
		$this->tokens->revoke($this->uid, (int)$person->getId());
		$this->assertNull($this->tokens->resolve($out2['token']));
	}

	public function testRequestApproveBecomesLoanInOneTx(): void
	{
		$suffix = bin2hex(random_bytes(3));
		[$loc, $item, $person] = $this->fixture($suffix);
		$r = $this->requests->submitForPerson(
			(int)$person->getId(), (int)$item['id'], null, 2, 'Carnival', null, null,
		);
		$this->assertSame('pending', $r->getStatus());
		// Approve without location must fail (member can't pick one).
		try {
			$this->requests->approve($this->uid, $r->getId(), null, 'ok', null);
			$this->fail('location required');
		} catch (ValidationException) {
		}
		$out = $this->requests->approve($this->uid, $r->getId(), (int)$loc['id'], 'ok', null);
		$this->assertSame('fulfilled', $out['request']->getStatus());
		$this->assertSame(2, $out['loan']->getQty());
		// Stock actually moved: 5 received − 2 lent = 3 left.
		$this->assertSame(3, $this->reservations->availability((int)$item['id'], (int)$loc['id'])['balance']);
		// Double-approve is a CAS conflict, never a second loan.
		$this->expectException(ConflictException::class);
		$this->requests->approve($this->uid, $r->getId(), (int)$loc['id'], 'ok', null);
	}

	public function testRequestCancelOwnOnly(): void
	{
		[, $item, $person] = $this->fixture(bin2hex(random_bytes(3)));
		[, , $other] = $this->fixture(bin2hex(random_bytes(3)));
		$r = $this->requests->submitForPerson((int)$person->getId(), (int)$item['id'], null, 1, null, null, null);
		// Foreign person → uniform 404, no oracle.
		try {
			$this->requests->cancelForPerson((int)$other->getId(), $r->getId());
			$this->fail('foreign cancel must 404');
		} catch (\OCA\InventoryCheck\Exception\NotFoundException) {
		}
		$this->requests->cancelForPerson((int)$person->getId(), $r->getId());
		$this->assertSame('cancelled', $this->requestMapper->findById($r->getId())->getStatus());
	}

	public function testDeclineWithNote(): void
	{
		[, $item, $person] = $this->fixture(bin2hex(random_bytes(3)));
		$r = $this->requests->submitForPerson((int)$person->getId(), (int)$item['id'], null, 1, null, null, null);
		$out = $this->requests->decline($this->uid, $r->getId(), 'no stock');
		$this->assertSame('declined', $out->getStatus());
		$this->assertSame('no stock', $out->getNote());
	}

	public function testReservationAvailabilityConvertAndExpiry(): void
	{
		$suffix = bin2hex(random_bytes(3));
		[$loc, $item, $person] = $this->fixture($suffix);
		$from = time() - 3600;
		$to = time() + 3600;
		$r = $this->reservations->create(
			$this->uid, (int)$item['id'], (int)$loc['id'], 3, $from, $to,
			(int)$person->getId(), 'Fasching',
		);
		$avail = $this->reservations->availability((int)$item['id'], (int)$loc['id']);
		$this->assertSame(5, $avail['balance']);
		$this->assertSame(3, $avail['reserved']);
		$this->assertSame(2, $avail['available']);
		// Convert → checkout + CAS fulfilled in one txn.
		$out = $this->reservations->convert($this->uid, $r->getId(), 'ok', null);
		$this->assertSame(3, $out['loan']->getQty());
		$this->assertSame('fulfilled', $this->reservationMapper->findById($r->getId())->getStatus());
		// Second convert conflicts.
		try {
			$this->reservations->convert($this->uid, $r->getId(), 'ok', null);
			$this->fail('double convert');
		} catch (ConflictException) {
		}
		// Expiry: a past reservation transitions via sweep (CAS idempotent).
		$stale = $this->reservations->create(
			$this->uid, (int)$item['id'], (int)$loc['id'], 1,
			time() - 7200, time() - 3600, null, 'Past event',
		);
		$this->assertGreaterThanOrEqual(1, $this->reservations->sweepExpired());
		$this->assertSame('expired', $this->reservationMapper->findById($stale->getId())->getStatus());
		$this->assertSame(0, $this->reservations->sweepExpired(), 'rerun idempotent');
	}

	public function testReservationValidation(): void
	{
		[$loc, $item] = [$this->locations->create($this->uid, ['code' => 'WC-V-' . bin2hex(random_bytes(3)), 'name' => 'V', 'kind' => 'site']), null];
		$item = $this->items->create($this->uid, ['sku' => 'WC-VI-' . bin2hex(random_bytes(3)), 'name' => 'x', 'uom' => 'pcs']);
		$this->expectException(ValidationException::class);
		$this->reservations->create($this->uid, (int)$item['id'], (int)$loc['id'], 1, time() + 7200, time() + 3600, null, null);
	}

	public function testOpenRequestCap(): void
	{
		[, $item, $person] = $this->fixture(bin2hex(random_bytes(3)));
		for ($i = 0; $i < 20; $i++) {
			$this->requests->submitForPerson((int)$person->getId(), (int)$item['id'], null, 1, null, null, null);
		}
		$this->expectException(ConflictException::class);
		$this->requests->submitForPerson((int)$person->getId(), (int)$item['id'], null, 1, null, null, null);
	}
}
