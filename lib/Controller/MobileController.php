<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Exception\AppAccessDeniedException;
use OCA\InventoryCheck\Exception\MobileGateException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\BalanceService;
use OCA\InventoryCheck\Service\DevicePairingService;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\LicenseService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\MobileGateService;
use OCA\InventoryCheck\Service\MovementService;
use OCA\InventoryCheck\Service\Pagination;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Mobile / scan API (SPEC §9).
 *
 * Device callers authenticate with X-IV-Device-Token only (no NC session),
 * so every route is PublicPage + NoCSRFRequired. Auth is enforced inside
 * resolveCaller / MobileGateService — never by SecurityMiddleware session.
 */
class MobileController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly MobileGateService $gate,
		private readonly LicenseService $license,
		private readonly DevicePairingService $pairing,
		private readonly ItemService $items,
		private readonly LocationService $locations,
		private readonly BalanceService $balances,
		private readonly MovementService $movements,
		private readonly AccessControlService $access,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function bootstrap(): JSONResponse
	{
		// SPEC §9.1: bootstrap skips rungs 3–6 only — auth + canUseApp still apply.
		[$uid, $device] = $this->resolveCaller(true);
		if ($uid !== null && !$this->access->canUseApp($uid)) {
			throw new AppAccessDeniedException(
				$this->access->denialReasonWhenCannotUseApp($uid),
			);
		}
		return new JSONResponse($this->gate->bootstrap($uid, $device));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function byCode(string $code): JSONResponse
	{
		[$uid, $device] = $this->resolveCaller(true);
		$this->gate->assertGate($uid, $device);
		return new JSONResponse($this->items->byCode(rawurldecode($code)));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function locations(): JSONResponse
	{
		[$uid, $device] = $this->resolveCaller(true);
		$this->gate->assertGate($uid, $device);
		$page = Pagination::parse($this->request->getParam('limit'), $this->request->getParam('offset'));
		return new JSONResponse($this->locations->list(true, $page['limit'], $page['offset']));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function balances(): JSONResponse
	{
		[$uid, $device] = $this->resolveCaller(true);
		$this->gate->assertGate($uid, $device);
		$page = Pagination::parse($this->request->getParam('limit'), $this->request->getParam('offset'));
		$itemId = $this->request->getParam('itemId');
		$locationId = $this->request->getParam('locationId');
		return new JSONResponse($this->balances->list(
			$itemId !== null && $itemId !== '' ? (int)$itemId : null,
			$locationId !== null && $locationId !== '' ? (int)$locationId : null,
			filter_var($this->request->getParam('nonZero', '0'), FILTER_VALIDATE_BOOLEAN),
			$page['limit'],
			$page['offset'],
		));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function movements(): JSONResponse
	{
		[$uid, $device] = $this->resolveCaller(true);
		$this->gate->assertGate($uid, $device);
		$page = Pagination::parse($this->request->getParam('limit'), $this->request->getParam('offset'));
		return new JSONResponse($this->movements->list(
			null, null, null, null, null, null, $page['limit'], $page['offset'],
		));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function scan(): JSONResponse
	{
		[$uid, $device] = $this->resolveCaller(true);
		$this->gate->assertGate($uid, $device);
		$p = $this->request->getParams();
		$asOffice = $device === null && $uid !== null && $this->access->isOffice($uid);
		$actor = $uid ?? ('device:' . (int)$device->getId());
		return new JSONResponse($this->movements->scan(
			$actor,
			(string)($p['code'] ?? ''),
			(string)($p['kind'] ?? ''),
			(int)($p['locationId'] ?? 0),
			isset($p['toLocationId']) ? (int)$p['toLocationId'] : null,
			isset($p['qty']) ? (int)$p['qty'] : null,
			isset($p['qtyDelta']) ? (int)$p['qtyDelta'] : null,
			isset($p['reason']) ? (string)$p['reason'] : null,
			$asOffice,
		));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function pairDevice(): JSONResponse
	{
		$code = (string)$this->request->getParam('code', '');
		return new JSONResponse($this->pairing->pair($code));
	}

	/**
	 * @return array{0: ?string, 1: ?\OCA\InventoryCheck\Db\ScanDevice}
	 */
	private function resolveCaller(bool $requireAuth): array
	{
		$token = (string)$this->request->getHeader('X-IV-Device-Token');
		if ($token !== '') {
			$hash = $this->license->hashSecret($token);
			$device = $this->license->findDeviceByTokenHash($hash);
			// SPEC §9.1: unknown / garbage token → 401 auth (rung 1).
			// Deactivated slot that still holds this hash → 402 device_required (rung 5 / §9.3.4).
			if ($device === null) {
				throw new MobileGateException('auth_required');
			}
			if (!$device->getActive()) {
				throw new MobileGateException('device_required');
			}
			$this->pairing->touchLastSeen($device);
			return [null, $device];
		}
		$user = $this->userSession->getUser();
		if ($user === null) {
			if ($requireAuth) {
				throw new MobileGateException('auth_required');
			}
			return [null, null];
		}
		return [$user->getUID(), null];
	}
}
