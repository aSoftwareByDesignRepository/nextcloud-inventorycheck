<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Db\ScanDevice;
use OCA\InventoryCheck\Exception\MobileGateException;
use OCA\InventoryCheck\License\Iv2Codec;
use OCP\IConfig;

/**
 * SPEC §9.1 gate ladder for mobile/device callers.
 */
class MobileGateService
{
	/**
	 * Companion API milestone ladder (feature-level documentation).
	 *
	 * bootstrap() advertises ONLY the breaking-change floor: BASE, or
	 * FRACTIONAL while qty_scale=3. Every milestone from WAVE_D up is an
	 * additive wave the client discovers through capabilities.* — a shipped
	 * companion must never floor-out on additive endpoints. Bump the floor
	 * only when a change breaks old binaries' wire parsing.
	 */
	private const COMPANION_API_BASE = 1;
	/** Breaking while qty_scale=3: qty fields move to milli-units on the wire. */
	private const COMPANION_API_FRACTIONAL = 2;
	/** Additive (capabilities.*): location by-code, reason codes, location-scan policy. */
	private const COMPANION_API_WAVE_D = 3;
	/** Additive (capabilities.*): mobile favourites + cycle-count endpoints for companion P1/P2. */
	private const COMPANION_API_MOBILE_STOCKTAKE = 4;
	/** Additive (capabilities.*): mobile read-only item photo GET. */
	private const COMPANION_API_ITEM_PHOTO = 5;
	/** Additive (capabilities.*): IV-COMPANION-TODAY (C-3) — /mobile/v1/today + /mobile/v1/lots/expiring. */
	private const COMPANION_API_TODAY = 6;
	/** Additive (capabilities.*): IV-RECEIVE-AGAINST-ORDER (C-8) — /mobile/v1/order-sheets* receive mirror. */
	private const COMPANION_API_ORDER_SHEETS = 7;

	public function __construct(
		private readonly LicenseService $license,
		private readonly AccessControlService $access,
		private readonly Clock $clock,
		private readonly IConfig $config,
		private readonly LocationAclService $locationAcl,
		private readonly ProductLookupService $productLookup,
	) {
	}

	/**
	 * Bootstrap skips rungs 3–6 and reports state.
	 *
	 * @return array<string, mixed>
	 */
	public function bootstrap(?string $userId, ?ScanDevice $device): array
	{
		$state = $this->license->findSingletonState();
		$licensing = null;
		if ($state !== null) {
			$licensing = [
				'format' => 'IV2',
				'payloadB64' => $state->getPayloadB64(),
				'signatureB64' => $state->getSignatureB64(),
			];
		}
		$seatAssigned = false;
		$seatWithinLimit = false;
		if ($userId !== null && $userId !== '') {
			$seat = $this->license->findSeatByUid($userId);
			if ($seat !== null) {
				$seatAssigned = true;
				$limit = $state?->getMobileSeats() ?? 0;
				$rankInput = array_map(
					static fn ($s) => ['id' => (int)$s->getId(), 'assignedAt' => $s->getAssignedAt()],
					$this->license->allSeats(),
				);
				$seatWithinLimit = SeatRank::isWithinLimit($rankInput, (int)$seat->getId(), $limit);
			}
		}
		$qtyScale = QtyScale::current($this->config);
		// Breaking-change floor only — additive waves (itemPhoto, today,
		// orderSheets, …) are advertised via capabilities.* below. The floor
		// rises solely when the wire contract breaks old binaries (fractional
		// qty), so an already-installed companion keeps working on every
		// additive wave.
		$companionApi = $qtyScale === QtyScale::SCALE_MILLI
			? self::COMPANION_API_FRACTIONAL
			: self::COMPANION_API_BASE;
		$isOffice = $userId !== null && $userId !== '' && $this->access->isOffice($userId);
		$deviceLocationIds = null;
		$deviceLocationUnbound = false;
		if ($device !== null) {
			$deviceLocationIds = $this->locationAcl->visibleLocationIds('device:' . (int)$device->getId());
			// Empty grant list under ACL = unbound / blocked scanner (fail closed).
			$deviceLocationUnbound = $this->locationAcl->isEnabled()
				&& is_array($deviceLocationIds)
				&& $deviceLocationIds === [];
		}
		return [
			'licensing' => $licensing,
			'seatAssigned' => $seatAssigned,
			'seatWithinLimit' => $seatWithinLimit,
			'devicePaired' => $device !== null,
			'mobileAppStatus' => LicenseService::MOBILE_APP_STATUS,
			'companionApi' => $companionApi,
			'requireAdjustReason' => ReasonCodes::isRequired($this->config),
			'requireLocationScan' => LocationScanPolicy::isRequired($this->config),
			'locationAclEnabled' => $this->locationAcl->isEnabled(),
			'locationAclDevicesStrict' => $this->locationAcl->isDevicesStrict(),
			'productLookupEnabled' => $this->productLookup->isEnabled(),
			/** null = unrestricted (ACL off or unbound device); list = restricted grants */
			'deviceLocationIds' => $deviceLocationIds,
			/** true when ACL is on, strict is off, and this scanner has zero grants */
			'deviceLocationUnbound' => $deviceLocationUnbound,
			'capabilities' => [
				'csv' => true,
				'photos' => true,
				'itemPhoto' => true,
				'cycleCount' => true,
				'bulkLabels' => true,
				'locationByCode' => true,
				'reasonCodes' => true,
				'favourites' => true,
				'deviceLocationAcl' => true,
				'deviceLocationStrict' => true,
				'productLookup' => true,
				// Restock wave: scan accepts spendMinor on office receives and
				// GET /mobile/v1/low-stock exists (office seats only).
				'receiveSpend' => true,
				'lowStock' => true,
				// IV-COMPANION-TODAY (C-3): expiring-lots feed is qty-only for
				// every gated caller; Chef-Blick carries money and is
				// advertised to office-entitled seats only (NN-01: absent /
				// false flag = the client never renders the strip).
				'expiringLots' => true,
				'todayChefView' => $isOffice,
				// C-8: order-sheet read + receive-against mirror. Office ACL is
				// still enforced per request — the flag only tells the client
				// the routes exist on this server.
				'orderSheets' => true,
				// F2 Wave B: custody checkout/checkin + person picker mirror
				// (seat/device — location ACL still scopes every request).
				'loans' => true,
				'qtyScale' => $qtyScale,
			],
			'reasonCodes' => ReasonCodes::catalog(),
			'user' => $userId,
			'isOffice' => $isOffice,
		];
	}

	/**
	 * Enforce full gate. Device callers skip canUseApp; treated as field.
	 */
	public function assertGate(?string $userId, ?ScanDevice $device): void
	{
		if ($userId !== null && $userId !== '') {
			if (!$this->access->canUseApp($userId)) {
				throw new \OCA\InventoryCheck\Exception\AppAccessDeniedException(
					$this->access->denialReasonWhenCannotUseApp($userId),
				);
			}
		}

		$state = $this->license->findSingletonState();
		if ($state === null) {
			throw new MobileGateException('license_missing');
		}
		if (!Iv2Codec::isValidOn($state->getValidUntil(), $this->clock->todayYmd())) {
			throw new MobileGateException('license_expired');
		}

		if ($device !== null) {
			$rankInput = array_map(
				static fn (ScanDevice $d) => ['id' => (int)$d->getId(), 'pairedAt' => (int)$d->getPairedAt()],
				$this->license->pairedActiveDevices(),
			);
			if (!DeviceRank::isWithinLimit($rankInput, (int)$device->getId(), $state->getScanDevices())) {
				throw new MobileGateException('device_limit_exceeded');
			}
			return;
		}

		if ($userId === null || $userId === '') {
			throw new MobileGateException('seat_required');
		}
		$seat = $this->license->findSeatByUid($userId);
		if ($seat === null) {
			throw new MobileGateException('seat_required');
		}
		$rankInput = array_map(
			static fn ($s) => ['id' => (int)$s->getId(), 'assignedAt' => $s->getAssignedAt()],
			$this->license->allSeats(),
		);
		if (!SeatRank::isWithinLimit($rankInput, (int)$seat->getId(), $state->getMobileSeats())) {
			throw new MobileGateException('seat_limit_exceeded');
		}
	}
}
