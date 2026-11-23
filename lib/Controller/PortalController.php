<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\LoanMapper;
use OCA\InventoryCheck\Db\ReservationMapper;
use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\Clock;
use OCA\InventoryCheck\Service\LoanRequestService;
use OCA\InventoryCheck\Service\MemberTokenService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;

/**
 * Wave C member portal (PORTAL-ABUSE-SPEC): token = credential, uniform
 * 404 for bad/revoked/foreign tokens, IThrottler-gated resolve + writes,
 * badge-only stock visibility (NN-01 — never exact numbers).
 */
class PortalController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly MemberTokenService $tokens,
		private readonly LoanRequestService $requests,
		private readonly LoanMapper $loans,
		private readonly ItemMapper $items,
		private readonly ReservationMapper $reservations,
		private readonly BalanceMapper $balances,
		private readonly AccessControlService $access,
		private readonly IThrottler $throttler,
		private readonly Clock $clock,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * GET /portal/{token} — guest HTML shell. Uniform for every token:
	 * validity is decided only by the throttled JSON API (zero oracle in
	 * the page itself).
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(string $token): PublicTemplateResponse
	{
		\OCP\Util::addScript(Application::APP_ID, 'portal');
		\OCP\Util::addStyle(Application::APP_ID, 'app');
		return new PublicTemplateResponse(Application::APP_ID, 'portal', [
			'token' => $token,
		]);
	}

	/** GET /portal/{token}/api — own loans + requests + requestable items */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function state(string $token): JSONResponse
	{
		[$person, ] = $this->resolveOr404($token, 'iv-portal');
		$now = $this->clock->now();
		$itemNames = [];
		$open = array_map(function ($l) use (&$itemNames, $now) {
			$itemNames[$l->getItemId()] ??= $this->itemName($l->getItemId());
			return [
				'id' => $l->getId(),
				'itemId' => $l->getItemId(),
				'itemName' => $itemNames[$l->getItemId()],
				'qty' => $l->getQty() - $l->getQtyReturned(),
				'dueAt' => $l->getDueAt(),
				'overdue' => $l->getDueAt() !== null && $l->getDueAt() < $now,
			];
		}, $this->loans->openForPerson($person->getId(), 50, 0));

		// Requestable items: active catalog rows with a badge — never a count.
		$itemRows = $this->items->search('', true, 200, 0)['data'] ?? [];
		$balances = $this->balances->sumQtyByItem(null);
		$requestable = array_map(function ($it) use ($balances, $now) {
			$bal = max(0, (int)($balances[$it->getId()] ?? 0));
			$res = $this->reservations->reservedQtyForItem($it->getId(), $now);
			$avail = $bal - $res;
			return [
				'id' => $it->getId(),
				'name' => $it->getName(),
				'badge' => $avail > 0 ? 'available' : ($bal > 0 ? 'reserved' : 'unavailable'),
			];
		}, $itemRows);

		return new JSONResponse([
			'person' => ['displayName' => $person->getDisplayName(), 'memberNo' => $person->getMemberNo()],
			'loans' => array_values($open),
			'requests' => $this->requests->listForPerson($person->getId(), 50),
			'items' => array_values($requestable),
		]);
	}

	/** POST /portal/{token}/requests {itemId, qty, purpose?, wantedFrom?, wantedTo?} */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function request(string $token): JSONResponse
	{
		[$person, ] = $this->resolveOr404($token, 'iv-portal-write');
		$itemId = is_numeric($this->request->getParam('itemId')) ? (int)$this->request->getParam('itemId') : 0;
		// Badge check: item must be requestable-visible right now.
		$this->assertRequestable($itemId);
		$r = $this->requests->submitForPerson(
			$person->getId(),
			$itemId,
			null,
			is_numeric($this->request->getParam('qty')) ? (int)$this->request->getParam('qty') : 0,
			$this->request->getParam('purpose'),
			is_numeric($this->request->getParam('wantedFrom')) ? (int)$this->request->getParam('wantedFrom') : null,
			is_numeric($this->request->getParam('wantedTo')) ? (int)$this->request->getParam('wantedTo') : null,
		);
		return new JSONResponse(['request' => $r->toApi()], 201);
	}

	/** POST /portal/{token}/requests/{id}/cancel */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function cancelRequest(string $token, int $id): JSONResponse
	{
		[$person, ] = $this->resolveOr404($token, 'iv-portal-write');
		$this->requests->cancelForPerson($person->getId(), $id);
		return new JSONResponse(['ok' => true]);
	}

	/** POST /portal/{token}/loans/{id}/damage {note} — member reports damage on own loan */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function damageReport(string $token, int $id): JSONResponse
	{
		[$person, ] = $this->resolveOr404($token, 'iv-portal-write');
		$note = trim((string)$this->request->getParam('note'));
		if ($note === '' || mb_strlen($note) > 2000) {
			throw new \OCA\InventoryCheck\Exception\ValidationException('validation_failed', '', [['field' => 'note', 'code' => 'validation_failed']]);
		}
		$loan = $this->loans->findById($id);
		if ($loan->getPersonId() !== $person->getId()) {
			throw new NotFoundException('unknown_loan');
		}
		$prev = $loan->getDamageNote();
		$stamp = '[member ' . date('Y-m-d', $this->clock->now()) . '] ' . $note;
		$loan->setDamageNote($prev === null || $prev === '' ? $stamp : $prev . "\n" . $stamp);
		$this->loans->update($loan);
		return new JSONResponse(['ok' => true]);
	}

	/**
	 * Resolve token → person, throttled + uniform 404. Registers an
	 * attempt for EVERY call — bad tokens are not free probes.
	 * @return array{0: \OCA\InventoryCheck\Db\Person, 1: \OCA\InventoryCheck\Db\MemberToken}
	 */
	private function resolveOr404(string $token, string $action): array
	{
		$ip = $this->request->getRemoteAddress() ?: 'unknown';
		try {
			$delay = $this->throttler->sleepDelayOrThrowOnMax($ip, $action);
		} catch (\Exception) {
			throw new NotFoundException('portal_not_found');
		}
		if ($delay > 0) {
			usleep($delay * 1000);
		}
		$resolved = $this->tokens->resolve($token);
		if ($resolved === null) {
			// Failed resolves register an attempt — token-guessing is the threat.
			$this->throttler->registerAttempt($action, $ip, ['route' => 'portal']);
			throw new NotFoundException('portal_not_found');
		}
		// Legitimate traffic clears accumulated delay — a busy member portal
		// behind a shared IP must not poison the IP-wide bruteforce warning.
		$this->throttler->resetDelay($ip, $action, ['route' => 'portal']);
		return $resolved;
	}

	private function assertRequestable(int $itemId): void
	{
		try {
			$item = $this->items->findById($itemId);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			throw new NotFoundException('portal_not_found');
		}
		if (method_exists($item, 'getActive') && !$item->getActive()) {
			throw new NotFoundException('portal_not_found');
		}
	}

	private function itemName(int $itemId): string
	{
		try {
			return (string)$this->items->findById($itemId)->getName();
		} catch (\Throwable) {
			return '';
		}
	}
}
