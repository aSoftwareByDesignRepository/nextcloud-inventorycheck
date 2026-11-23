<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\Loan;
use OCA\InventoryCheck\Db\LoanMapper;
use OCA\InventoryCheck\Db\LocationMapper;
use OCA\InventoryCheck\Db\Person;
use OCA\InventoryCheck\Db\PersonMapper;
use OCA\InventoryCheck\Exception\ConflictException;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\Clock;
use OCA\InventoryCheck\Service\LoanService;
use OCA\InventoryCheck\Service\LocationAclService;
use OCA\InventoryCheck\Service\MovementService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * F2 Wave A guards: person loanability, condition whitelist, due bounds,
 * over-return field error, closed-loan rejection, uniform-404 scoping.
 * The movement txn itself is proved by the integration suite.
 */
final class LoanServiceTest extends TestCase
{
	private function person(array $over = []): Person
	{
		$p = new Person();
		$p->setId($over['id'] ?? 7);
		$p->setDisplayName($over['name'] ?? 'Mia');
		$p->setActive($over['active'] ?? 1);
		$p->setAnonymized($over['anon'] ?? 0);
		return $p;
	}

	private function loan(array $over = []): Loan
	{
		$l = new Loan();
		$l->setId($over['id'] ?? 3);
		$l->setItemId(11);
		$l->setPersonId(7);
		$l->setLocationId(5);
		$l->setQty($over['qty'] ?? 2);
		$l->setQtyReturned($over['returned'] ?? 0);
		$l->setStatus($over['status'] ?? Loan::STATUS_OPEN);
		return $l;
	}

	private function service(array $m): LoanService
	{
		return new LoanService(
			$m['loans'] ?? $this->createMock(LoanMapper::class),
			$m['persons'] ?? $this->createMock(PersonMapper::class),
			$m['items'] ?? $this->createMock(ItemMapper::class),
			$m['locations'] ?? $this->createMock(LocationMapper::class),
			$m['acl'] ?? $this->createMock(LocationAclService::class),
			$m['movements'] ?? $this->createMock(MovementService::class),
			$m['access'] ?? $this->accessMock(),
			$this->clock(),
			new NullLogger(),
		);
	}

	private function accessMock(): AccessControlService
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('requireOffice');
		return $access;
	}

	private function clock(): Clock
	{
		$clock = $this->createMock(Clock::class);
		$clock->method('now')->willReturn(1_763_900_000);
		return $clock;
	}

	public function testCheckoutRejectsInactivePerson(): void
	{
		$persons = $this->createMock(PersonMapper::class);
		$persons->method('findById')->willReturn($this->person(['active' => 0]));
		$svc = $this->service(['persons' => $persons]);
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('person_not_loanable');
		$svc->checkout('admin', 1, 1, 7, 1, null, null, null, null);
	}

	public function testCheckoutRejectsAnonymizedPerson(): void
	{
		$persons = $this->createMock(PersonMapper::class);
		$persons->method('findById')->willReturn($this->person(['anon' => 1]));
		$svc = $this->service(['persons' => $persons]);
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('person_not_loanable');
		$svc->checkout('admin', 1, 1, 7, 1, null, null, null, null);
	}

	public function testCheckoutRejectsUnknownPersonAs404(): void
	{
		$persons = $this->createMock(PersonMapper::class);
		$persons->method('findById')->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));
		$svc = $this->service(['persons' => $persons]);
		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('unknown_person');
		$svc->checkout('admin', 1, 1, 99, 1, null, null, null, null);
	}

	public function testCheckoutRejectsBadCondition(): void
	{
		$persons = $this->createMock(PersonMapper::class);
		$persons->method('findById')->willReturn($this->person());
		$svc = $this->service(['persons' => $persons]);
		$this->expectException(ValidationException::class);
		$svc->checkout('admin', 1, 1, 7, 1, null, null, 'exploding', null);
	}

	public function testCheckoutAcceptsDueAtUpperBound(): void
	{
		// dueAt must be strictly > bound to reject; at the bound the next
		// guard (condition whitelist) throws instead. Mutating `>` to `>=`
		// makes this throw for field 'dueAt' instead of 'conditionOut'.
		$persons = $this->createMock(PersonMapper::class);
		$persons->method('findById')->willReturn($this->person());
		$svc = $this->service(['persons' => $persons]);
		try {
			$svc->checkout('admin', 1, 1, 7, 1, 4_102_444_800, null, 'exploding', null);
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('conditionOut', $e->getDetails()[0]['field']);
		}
	}

	public function testCheckinRejectsBadCondition(): void
	{
		$loans = $this->createMock(LoanMapper::class);
		$loans->method('findById')->willReturn($this->loan());
		$acl = $this->createMock(LocationAclService::class);
		$acl->method('canAccessLocation')->willReturn(true);
		$svc = $this->service(['loans' => $loans, 'acl' => $acl]);
		$this->expectException(ValidationException::class);
		$svc->checkin('admin', 3, 1, 'shiny', null);
	}

	public function testCheckinUniform404ForInaccessibleLocation(): void
	{
		$loans = $this->createMock(LoanMapper::class);
		$loans->method('findById')->willReturn($this->loan());
		$acl = $this->createMock(LocationAclService::class);
		$acl->method('canAccessLocation')->willReturn(false);
		$svc = $this->service(['loans' => $loans, 'acl' => $acl]);
		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('unknown_loan');
		$svc->checkin('admin', 3, 1, 'ok', null);
	}

	public function testMarkLostRejectsClosedLoan(): void
	{
		$loans = $this->createMock(LoanMapper::class);
		$loans->method('findById')->willReturn($this->loan(['status' => Loan::STATUS_RETURNED]));
		$acl = $this->createMock(LocationAclService::class);
		$acl->method('canAccessLocation')->willReturn(true);
		$svc = $this->service(['loans' => $loans, 'acl' => $acl]);
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('loan_not_open');
		$svc->markLost('admin', 3, null);
	}

	public function testMarkLostUpdatesTerminalState(): void
	{
		$loan = $this->loan();
		$loans = $this->createMock(LoanMapper::class);
		$loans->method('findById')->willReturn($loan);
		$loans->expects($this->once())->method('update')
			->willReturnCallback(fn (Loan $l): Loan => $l);
		$acl = $this->createMock(LocationAclService::class);
		$acl->method('canAccessLocation')->willReturn(true);
		$svc = $this->service(['loans' => $loans, 'acl' => $acl]);
		$out = $svc->markLost('admin', 3, 'written off');
		$this->assertSame(Loan::STATUS_LOST, $out->getStatus());
		$this->assertSame('written off', $out->getDamageNote());
	}
}
