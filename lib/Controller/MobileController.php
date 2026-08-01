<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Exception\AppAccessDeniedException;
use OCA\InventoryCheck\Exception\MobileGateException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\BalanceService;
use OCA\InventoryCheck\Service\CycleCountService;
use OCA\InventoryCheck\Service\DevicePairingService;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\LicenseService;
use OCA\InventoryCheck\Service\LocationFavouriteService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\MobileGateService;
use OCA\InventoryCheck\Service\MovementService;
use OCA\InventoryCheck\Service\Pagination;
use OCA\InventoryCheck\Service\QtyScale;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
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
		private readonly LocationFavouriteService $favourites,
		private readonly CycleCountService $cycles,
		private readonly IUserSession $userSession,
		private readonly IConfig $config,
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
		$item = QtyScale::formatItem(
			$this->items->byCode($uid ?? ('device:' . (int)$device->getId()), rawurldecode($code)),
			$this->config,
		);
		if (isset($item['balances']) && is_array($item['balances'])) {
			$item['balances'] = array_map(
				fn (array $b) => QtyScale::formatBalance($b, $this->config),
				$item['balances'],
			);
		}
		return new JSONResponse($item);
	}

	/** Wave D2 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function locationByCode(string $code): JSONResponse
	{
		[$uid, $device] = $this->resolveCaller(true);
		$this->gate->assertGate($uid, $device);
		return new JSONResponse(
			$this->locations->byCode($uid ?? ('device:' . (int)$device->getId()), rawurldecode($code)),
		);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function locations(): JSONResponse
	{
		[$uid, $device] = $this->resolveCaller(true);
		$this->gate->assertGate($uid, $device);
		$page = Pagination::parse($this->request->getParam('limit'), $this->request->getParam('offset'));
		// Device tokens (no uid) stay unrestricted; session-backed mobile
		// users honour Wave C3 location ACL like the web app.
		return new JSONResponse($this->locations->list($uid ?? '', true, $page['limit'], $page['offset']));
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
		$result = $this->balances->list(
			$uid ?? '',
			$itemId !== null && $itemId !== '' ? (int)$itemId : null,
			$locationId !== null && $locationId !== '' ? (int)$locationId : null,
			filter_var($this->request->getParam('nonZero', '0'), FILTER_VALIDATE_BOOLEAN),
			$page['limit'],
			$page['offset'],
		);
		$result['data'] = array_map(
			fn (array $b) => QtyScale::formatBalance($b, $this->config),
			$result['data'],
		);
		return new JSONResponse($result);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function movements(): JSONResponse
	{
		[$uid, $device] = $this->resolveCaller(true);
		$this->gate->assertGate($uid, $device);
		$page = Pagination::parse($this->request->getParam('limit'), $this->request->getParam('offset'));
		$result = $this->movements->list(
			$uid ?? '',
			null, null, null, null, null, null, $page['limit'], $page['offset'],
		);
		$result['data'] = array_map(
			fn (array $m) => QtyScale::formatMovement($m, $this->config),
			$result['data'],
		);
		return new JSONResponse($result);
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
		$lotCode = null;
		if (isset($p['lotCode']) && $p['lotCode'] !== '') {
			$lotCode = (string)$p['lotCode'];
		}
		$result = $this->movements->scan(
			$actor,
			(string)($p['code'] ?? ''),
			(string)($p['kind'] ?? ''),
			(int)($p['locationId'] ?? 0),
			isset($p['toLocationId']) ? (int)$p['toLocationId'] : null,
			isset($p['qty']) ? QtyScale::toStorage($this->config, $p['qty']) : null,
			isset($p['qtyDelta']) ? QtyScale::toStorage($this->config, $p['qtyDelta']) : null,
			isset($p['reason']) ? (string)$p['reason'] : null,
			$asOffice,
			$lotCode,
			isset($p['reasonCode']) ? (string)$p['reasonCode'] : (isset($p['reason_code']) ? (string)$p['reason_code'] : null),
			isset($p['locationCode']) ? (string)$p['locationCode'] : (isset($p['location_code']) ? (string)$p['location_code'] : null),
		);
		$result['movements'] = array_map(
			fn (array $m) => QtyScale::formatMovement($m, $this->config),
			$result['movements'],
		);
		$result['balances'] = array_map(
			fn (array $b) => QtyScale::formatBalance($b, $this->config),
			$result['balances'],
		);
		return new JSONResponse($result);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function pairDevice(): JSONResponse
	{
		$code = (string)$this->request->getParam('code', '');
		return new JSONResponse($this->pairing->pair($code));
	}

	/** Companion P1 — favourites (session users only; devices have no favourites). */
	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function favourites(): JSONResponse
	{
		[$uid] = $this->requireSessionUser();
		$this->gate->assertGate($uid, null);
		return new JSONResponse(['data' => $this->favourites->list($uid)]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function addFavourite(): JSONResponse
	{
		[$uid] = $this->requireSessionUser();
		$this->gate->assertGate($uid, null);
		$locationId = (int)$this->request->getParam('locationId', 0);
		return new JSONResponse(['data' => $this->favourites->add($uid, $locationId)]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function removeFavourite(int $locationId): JSONResponse
	{
		[$uid] = $this->requireSessionUser();
		$this->gate->assertGate($uid, null);
		return new JSONResponse(['data' => $this->favourites->remove($uid, $locationId)]);
	}

	/** Companion P2 — stocktake (session users; devices cannot inventur). */
	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function cycleCounts(): JSONResponse
	{
		[$uid] = $this->requireSessionUser();
		$this->gate->assertGate($uid, null);
		$page = Pagination::parse($this->request->getParam('limit'), $this->request->getParam('offset'));
		$status = (string)$this->request->getParam('status', '');
		return new JSONResponse($this->cycles->list(
			$uid,
			$status !== '' ? $status : null,
			$page['limit'],
			$page['offset'],
		));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function cycleCountShow(int $id): JSONResponse
	{
		[$uid] = $this->requireSessionUser();
		$this->gate->assertGate($uid, null);
		$blind = filter_var($this->request->getParam('blind', '0'), FILTER_VALIDATE_BOOLEAN);
		$campaign = $this->formatCycleCampaign($this->cycles->get($uid, $id), $blind);
		return new JSONResponse($campaign);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function cycleCountSetCount(int $lineId): JSONResponse
	{
		[$uid] = $this->requireSessionUser();
		$this->gate->assertGate($uid, null);
		$p = $this->request->getParams();
		$line = $this->cycles->setCount(
			$uid,
			$lineId,
			QtyScale::toStorage($this->config, $p['qtyCounted'] ?? 0),
		);
		return new JSONResponse(QtyScale::formatCycleLine($line, $this->config));
	}

	/**
	 * @return array{0: string, 1: null}
	 */
	private function requireSessionUser(): array
	{
		[$uid, $device] = $this->resolveCaller(true);
		if ($device !== null || $uid === null) {
			// Device tokens are field-only; inventur/favourites need a named seat.
			throw new MobileGateException('auth_required');
		}
		return [$uid, null];
	}

	/**
	 * @param array<string, mixed> $campaign
	 * @return array<string, mixed>
	 */
	private function formatCycleCampaign(array $campaign, bool $blind): array
	{
		if (isset($campaign['lines']) && is_array($campaign['lines'])) {
			$campaign['lines'] = array_map(
				function (array $line) use ($blind): array {
					$formatted = QtyScale::formatCycleLine($line, $this->config);
					if ($blind) {
						// AF-IV13: never leak system/current qty in blind mode.
						unset($formatted['systemQty'], $formatted['currentQty']);
						$formatted['blind'] = true;
					}
					return $formatted;
				},
				$campaign['lines'],
			);
		}
		$campaign['blind'] = $blind;
		return $campaign;
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
