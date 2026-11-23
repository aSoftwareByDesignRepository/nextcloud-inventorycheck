<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Controller;

use OCA\InventoryCheck\Controller\PortalController;
use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Db\Item;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\Loan;
use OCA\InventoryCheck\Db\LoanMapper;
use OCA\InventoryCheck\Db\LoanRequest;
use OCA\InventoryCheck\Db\LoanRequestMapper;
use OCA\InventoryCheck\Db\MemberToken;
use OCA\InventoryCheck\Db\Person;
use OCA\InventoryCheck\Db\ReservationMapper;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\Clock;
use OCA\InventoryCheck\Service\LoanRequestService;
use OCA\InventoryCheck\Service\MemberTokenService;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Wave C portal controller — the resolve guard is the security boundary:
 * happy path returns badge-only own-scope data; bad tokens are a uniform
 * 404 that registers a bruteforce attempt; good tokens reset the delay
 * (no IP-wide warning poisoning — see bugs/20261124-portal-throttle-*).
 */
final class PortalControllerTest extends TestCase
{
	private const NOW = 1_800_000_000;

	private MemberTokenService&MockObject $tokens;
	private LoanRequestService&MockObject $requests;
	private LoanMapper&MockObject $loans;
	private ItemMapper&MockObject $items;
	private ReservationMapper&MockObject $reservations;
	private BalanceMapper&MockObject $balances;
	private IThrottler&MockObject $throttler;

	private function controller(array $params = []): PortalController
	{
		$this->tokens = $this->createMock(MemberTokenService::class);
		$this->requests = $this->createMock(LoanRequestService::class);
		$this->loans = $this->createMock(LoanMapper::class);
		$this->items = $this->createMock(ItemMapper::class);
		$this->reservations = $this->createMock(ReservationMapper::class);
		$this->balances = $this->createMock(BalanceMapper::class);
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('alice');
		$this->throttler = $this->createMock(IThrottler::class);
		$this->throttler->method('sleepDelayOrThrowOnMax')->willReturn(0);
		$clock = $this->createMock(Clock::class);
		$clock->method('now')->willReturn(self::NOW);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => $params[$key] ?? $default,
		);
		$request->method('getRemoteAddress')->willReturn('203.0.113.7');

		return new PortalController(
			$request,
			$this->tokens,
			$this->requests,
			$this->loans,
			$this->items,
			$this->reservations,
			$this->balances,
			$access,
			$this->throttler,
			$clock,
		);
	}

	private function person(): Person
	{
		$p = new Person();
		$p->setId(7);
		$p->setDisplayName('Mara Member');
		$p->setMemberNo('M-100');
		return $p;
	}

	private function token(MemberTokenService&MockObject $tokens, ?Person $person): void
	{
		$tokens->method('resolve')->willReturnCallback(
			static fn (string $raw) => $raw === 'ivm_good' && $person !== null
				? [$person, new MemberToken()]
				: null,
		);
	}

	private function item(int $id, string $name): Item
	{
		$it = new Item();
		$it->setId($id);
		$it->setName($name);
		$it->setActive(true);
		return $it;
	}

	public function testStateReturnsOwnScopeWithBadgeOnlyItems(): void
	{
		$ctl = $this->controller();
		$this->token($this->tokens, $this->person());

		$loan = new Loan();
		$loan->setId(11);
		$loan->setItemId(5);
		$loan->setPersonId(7);
		$loan->setQty(2);
		$loan->setQtyReturned(0);
		$loan->setDueAt(self::NOW + 86400);
		$this->loans->method('openForPerson')->with(7, 50, 0)->willReturn([$loan]);

		$this->items->method('findById')->with(5)->willReturn($this->item(5, 'Drill'));
		$this->items->method('search')->willReturn(['data' => [$this->item(5, 'Drill')]]);
		$this->balances->method('sumQtyByItem')->willReturn([5 => 3]);
		$this->reservations->method('reservedQtyForItem')->willReturn(0);
		$this->requests->method('listForPerson')->with(7, 50)->willReturn([]);

		$res = $ctl->state('ivm_good');
		self::assertSame(200, $res->getStatus());
		$data = $res->getData();

		self::assertSame('Mara Member', $data['person']['displayName']);
		self::assertCount(1, $data['loans']);
		self::assertSame(11, $data['loans'][0]['id']);
		self::assertSame('Drill', $data['loans'][0]['itemName']);
		self::assertFalse($data['loans'][0]['overdue']);

		// NN-01: badge only — never a numeric count on the member surface.
		self::assertSame('available', $data['items'][0]['badge']);
		self::assertArrayNotHasKey('qty', $data['items'][0]);
		self::assertArrayNotHasKey('balance', $data['items'][0]);
		self::assertArrayNotHasKey('available', $data['items'][0]);
	}

	public function testStateReservesBadgeWhenStockHeld(): void
	{
		$ctl = $this->controller();
		$this->token($this->tokens, $this->person());
		$this->loans->method('openForPerson')->willReturn([]);
		$this->items->method('search')->willReturn(['data' => [$this->item(5, 'Drill')]]);
		$this->balances->method('sumQtyByItem')->willReturn([5 => 3]);
		$this->reservations->method('reservedQtyForItem')->with(5, self::NOW)->willReturn(3);
		$this->requests->method('listForPerson')->willReturn([]);

		$data = $ctl->state('ivm_good')->getData();
		self::assertSame('reserved', $data['items'][0]['badge']);
	}

	public function testBadTokenIsUniform404AndRegistersAttempt(): void
	{
		$ctl = $this->controller();
		$this->token($this->tokens, $this->person());
		$this->throttler->expects($this->once())
			->method('registerAttempt')
			->with('iv-portal', '203.0.113.7');
		$this->throttler->expects($this->never())->method('resetDelay');

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('portal_not_found');
		$ctl->state('ivm_evil');
	}

	public function testGoodTokenResetsDelayNeverRegisters(): void
	{
		$ctl = $this->controller();
		$this->token($this->tokens, $this->person());
		$this->throttler->expects($this->once())
			->method('resetDelay')
			->with('203.0.113.7', 'iv-portal');
		$this->throttler->expects($this->never())->method('registerAttempt');
		$this->loans->method('openForPerson')->willReturn([]);
		$this->items->method('search')->willReturn(['data' => []]);
		$this->balances->method('sumQtyByItem')->willReturn([]);
		$this->requests->method('listForPerson')->willReturn([]);

		$ctl->state('ivm_good');
	}

	public function testThrottleHardBlockCollapsesTo404(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getRemoteAddress')->willReturn('203.0.113.7');
		$request->method('getParam')->willReturn(null);
		$tokens = $this->createMock(MemberTokenService::class);
		$throttler = $this->createMock(IThrottler::class);
		$throttler->method('sleepDelayOrThrowOnMax')
			->willThrowException(new \OCP\Security\Bruteforce\MaxDelayReached());
		$clock = $this->createMock(Clock::class);
		$clock->method('now')->willReturn(self::NOW);
		$ctl = new PortalController(
			$request, $tokens,
			$this->createMock(LoanRequestService::class),
			$this->createMock(LoanMapper::class),
			$this->createMock(ItemMapper::class),
			$this->createMock(ReservationMapper::class),
			$this->createMock(BalanceMapper::class),
			$this->createMock(AccessControlService::class),
			$throttler, $clock,
		);
		$tokens->expects($this->never())->method('resolve');

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('portal_not_found');
		$ctl->state('ivm_good');
	}

	public function testDamageReportForeignLoanIsUniform404(): void
	{
		$ctl = $this->controller(['note' => 'cracked']);
		$this->token($this->tokens, $this->person());
		$loan = new Loan();
		$loan->setId(11);
		$loan->setPersonId(99); // not the token's person
		$this->loans->method('findById')->with(11)->willReturn($loan);
		$this->loans->expects($this->never())->method('update');

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('unknown_loan');
		$ctl->damageReport('ivm_good', 11);
	}

	public function testDamageReportBlankNoteIsValidationError(): void
	{
		$ctl = $this->controller(['note' => '   ']);
		$this->token($this->tokens, $this->person());
		$this->loans->expects($this->never())->method('findById');

		$this->expectException(ValidationException::class);
		$ctl->damageReport('ivm_good', 11);
	}

	public function testDamageReportAppendsStampOnOwnLoan(): void
	{
		$ctl = $this->controller(['note' => 'case cracked']);
		$this->token($this->tokens, $this->person());
		$loan = new Loan();
		$loan->setId(11);
		$loan->setPersonId(7);
		$loan->setDamageNote('prior note');
		$this->loans->method('findById')->with(11)->willReturn($loan);
		$this->loans->expects($this->once())->method('update')
			->with(self::callback(
				static fn (Loan $l) => str_contains((string)$l->getDamageNote(), 'case cracked')
					&& str_contains((string)$l->getDamageNote(), 'prior note'),
			));

		$res = $ctl->damageReport('ivm_good', 11);
		self::assertSame(200, $res->getStatus());
	}

	public function testRequestSubmissionBindsToResolvedPerson(): void
	{
		$ctl = $this->controller(['itemId' => 5, 'qty' => 2, 'purpose' => 'weekend']);
		$this->token($this->tokens, $this->person());
		$this->items->method('findById')->with(5)->willReturn($this->item(5, 'Drill'));

		$req = new LoanRequest();
		$req->setId(21);
		$req->setItemId(5);
		$req->setPersonId(7);
		$req->setRequesterUid('portal');
		$req->setQty(2);
		$req->setStatus('pending');
		$req->setCreatedAt(self::NOW);
		$this->requests->expects($this->once())->method('submitForPerson')
			->with(7, 5, null, 2, 'weekend', null, null)
			->willReturn($req);

		$res = $ctl->request('ivm_good');
		self::assertSame(201, $res->getStatus());
		self::assertSame(21, $res->getData()['request']['id']);
	}

	public function testCancelIsScopedToResolvedPerson(): void
	{
		$ctl = $this->controller();
		$this->token($this->tokens, $this->person());
		$this->requests->expects($this->once())
			->method('cancelForPerson')
			->with(7, 21);

		self::assertSame(200, $ctl->cancelRequest('ivm_good', 21)->getStatus());
	}
}
