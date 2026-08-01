<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Db\ScanDevice;
use OCA\InventoryCheck\Exception\MobileGateException;
use OCA\InventoryCheck\License\Iv2Codec;
use OCP\IConfig;

/**
 * SPEC §9.1 gate ladder for mobile/device callers.
 */
class MobileGateService
{
	/** Wave C1: companion API bumps to 2 once fractional (qty_scale=3) is enabled. */
	private const COMPANION_API_BASE = 1;
	private const COMPANION_API_FRACTIONAL = 2;
	/** Wave D: location by-code, reason codes, location-scan policy. */
	private const COMPANION_API_WAVE_D = 3;
	/** Mobile favourites + cycle-count endpoints for companion P1/P2. */
	private const COMPANION_API_MOBILE_STOCKTAKE = 4;

	public function __construct(
		private readonly LicenseService $license,
		private readonly AccessControlService $access,
		private readonly Clock $clock,
		private readonly IConfig $config,
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
		$companionApi = self::COMPANION_API_MOBILE_STOCKTAKE;
		if ($qtyScale === QtyScale::SCALE_MILLI && $companionApi < self::COMPANION_API_FRACTIONAL) {
			$companionApi = self::COMPANION_API_FRACTIONAL;
		}
		$isOffice = $userId !== null && $userId !== '' && $this->access->isOffice($userId);
		return [
			'licensing' => $licensing,
			'seatAssigned' => $seatAssigned,
			'seatWithinLimit' => $seatWithinLimit,
			'devicePaired' => $device !== null,
			'mobileAppStatus' => LicenseService::MOBILE_APP_STATUS,
			'companionApi' => $companionApi,
			'requireAdjustReason' => ReasonCodes::isRequired($this->config),
			'requireLocationScan' => LocationScanPolicy::isRequired($this->config),
			'capabilities' => [
				'csv' => true,
				'photos' => true,
				'cycleCount' => true,
				'bulkLabels' => true,
				'locationByCode' => true,
				'reasonCodes' => true,
				'favourites' => true,
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
