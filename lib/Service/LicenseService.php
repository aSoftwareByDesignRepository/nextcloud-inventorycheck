<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Db\LicenseState;
use OCA\InventoryCheck\Db\LicenseStateMapper;
use OCA\InventoryCheck\Db\MobileSeat;
use OCA\InventoryCheck\Db\MobileSeatMapper;
use OCA\InventoryCheck\Db\ScanDevice;
use OCA\InventoryCheck\Db\ScanDeviceMapper;
use OCA\InventoryCheck\Exception\ConflictException;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\License\Iv2Codec;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Track L (SPEC §8–§9): IV2 singleton + seats + device slots.
 */
class LicenseService
{
	public const MOBILE_APP_STATUS = 'coming_soon';

	private const LICENSE_LOCK = 'inventorycheck/license_apply';
	private const SEAT_LOCK = 'inventorycheck/seat_assign';
	private const DEVICE_LOCK = 'inventorycheck/device_create';

	public function __construct(
		private readonly IDBConnection $db,
		private readonly LicenseStateMapper $licenseState,
		private readonly MobileSeatMapper $seats,
		private readonly ScanDeviceMapper $devices,
		private readonly Clock $clock,
		private readonly IUserManager $userManager,
		private readonly ILockingProvider $locking,
		private readonly IConfig $config,
	) {
	}

	/** @return array<string, mixed> */
	public function status(): array
	{
		$state = $this->licenseState->findSingleton();
		return [
			'state' => $state === null ? null : [
				'customerId' => $state->getCustomerId(),
				'issuedAt' => $state->getIssuedAt(),
				'validUntil' => $state->getValidUntil(),
				'mobileSeats' => $state->getMobileSeats(),
				'scanDevices' => $state->getScanDevices(),
				'bundle' => $state->getBundle(),
				'valid' => Iv2Codec::isValidOn($state->getValidUntil(), $this->clock->todayYmd()),
				'appliedAt' => $state->getAppliedAt(),
				'appliedBy' => $state->getAppliedBy(),
			],
			'seats' => [
				'assigned' => $this->seats->countAll(),
				'limit' => $state?->getMobileSeats() ?? 0,
			],
			'devices' => [
				'active' => $this->devices->countActive(),
				'limit' => $state?->getScanDevices() ?? 0,
			],
			'mobileAppStatus' => self::MOBILE_APP_STATUS,
		];
	}

	/** @return array<string, mixed> */
	public function apply(string $uid, string $wireKey): array
	{
		return $this->withExclusiveLock(self::LICENSE_LOCK, 'license_busy', function () use ($uid, $wireKey): array {
			$error = Iv2Codec::classifyError($wireKey);
			if ($error !== '') {
				$message = match ($error) {
					Iv2Codec::ERROR_INVALID_FORMAT => 'The key does not have the expected IV2.<payload>.<signature> shape.',
					Iv2Codec::ERROR_INVALID_SIGNATURE => 'The signature does not match — the key was altered or not issued for this product.',
					default => 'The key payload failed validation for InventoryCheck.',
				};
				throw new ValidationException('license_invalid', $message);
			}

			/** @var array{payload: array<string, mixed>, payloadB64: string, signatureB64: string} $verified */
			$verified = Iv2Codec::parseAndVerify($wireKey);
			$payload = $verified['payload'];

			$state = new LicenseState();
			$state->setCustomerId((string)$payload['customerId']);
			$state->setIssuedAt((string)$payload['issuedAt']);
			$state->setValidUntil((string)$payload['validUntil']);
			$state->setMobileSeats((int)$payload['mobileSeats']);
			$state->setScanDevices((int)$payload['scanDevices']);
			$state->setBundle(($payload['bundle'] ?? false) === true);
			$state->setPayloadB64($verified['payloadB64']);
			$state->setSignatureB64($verified['signatureB64']);
			$state->setAppliedAt($this->clock->now());
			$state->setAppliedBy($uid);

			$this->db->beginTransaction();
			try {
				$this->licenseState->deleteAll();
				$this->licenseState->insert($state);
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}

			return $this->status();
		});
	}

	/** @return array<string, mixed> */
	public function remove(): array
	{
		$this->licenseState->deleteAll();
		return $this->status();
	}

	/** @return array{data: list<array<string, mixed>>, total: int, limit: int, offset: int} */
	public function listSeats(int $limit, int $offset): array
	{
		$ranked = $this->seats->findAllRanked();
		$seatLimit = $this->licenseState->findSingleton()?->getMobileSeats() ?? 0;
		$rankInput = array_map(
			static fn (MobileSeat $s): array => ['id' => (int)$s->getId(), 'assignedAt' => $s->getAssignedAt()],
			$ranked,
		);
		$rows = [];
		foreach ($ranked as $seat) {
			$rows[] = [
				'uid' => $seat->getUid(),
				'displayName' => $this->userManager->get($seat->getUid())?->getDisplayName() ?? $seat->getUid(),
				'assignedAt' => $seat->getAssignedAt(),
				'assignedBy' => $seat->getAssignedBy(),
				'withinLimit' => SeatRank::isWithinLimit($rankInput, (int)$seat->getId(), $seatLimit),
			];
		}
		return [
			'data' => array_slice($rows, $offset, $limit),
			'total' => count($rows),
			'limit' => $limit,
			'offset' => $offset,
		];
	}

	/** @return array<string, mixed> */
	public function assignSeat(string $adminUid, mixed $userId): array
	{
		if (!is_string($userId) || trim($userId) === '') {
			throw new ValidationException('unknown_user');
		}
		$userId = trim($userId);
		if (!$this->userManager->userExists($userId)) {
			throw new ValidationException('unknown_user');
		}
		$existing = $this->seats->findByUid($userId);
		if ($existing !== null) {
			return $this->seatRow($existing);
		}

		return $this->withExclusiveLock(self::SEAT_LOCK, 'license_busy', function () use ($adminUid, $userId): array {
			$existing = $this->seats->findByUid($userId);
			if ($existing !== null) {
				return $this->seatRow($existing);
			}
			$limit = $this->licenseState->findSingleton()?->getMobileSeats() ?? 0;
			if ($this->seats->countAll() >= $limit) {
				throw new ConflictException('seat_limit_reached');
			}
			$seat = new MobileSeat();
			$seat->setUid($userId);
			$seat->setAssignedAt($this->clock->now());
			$seat->setAssignedBy($adminUid);
			try {
				$seat = $this->seats->insert($seat);
			} catch (\Throwable $e) {
				$existing = $this->seats->findByUid($userId);
				if ($existing !== null) {
					return $this->seatRow($existing);
				}
				throw $e;
			}
			return $this->seatRow($seat);
		});
	}

	public function removeSeat(string $uid): void
	{
		// User-delete must not fatal when a sibling install never created the
		// companion seat table (migrations marked complete without effect).
		if (!$this->db->tableExists(MobileSeatMapper::TABLE)) {
			return;
		}
		$seat = $this->seats->findByUid($uid);
		if ($seat === null) {
			return;
		}
		$this->seats->delete($seat);
	}

	/**
	 * Create device slot with one-time pairing code (returned once).
	 *
	 * @return array{device: array<string, mixed>, pairCode: string}
	 */
	public function createDevice(string $adminUid, string $label): array
	{
		$label = CodeRules::trim($label);
		if ($label === '' || mb_strlen($label) > 255) {
			throw new ValidationException('validation_failed', '', [
				['field' => 'label', 'code' => 'validation_failed'],
			]);
		}

		return $this->withExclusiveLock(self::DEVICE_LOCK, 'license_busy', function () use ($adminUid, $label): array {
			$limit = $this->licenseState->findSingleton()?->getScanDevices() ?? 0;
			if ($this->devices->countActive() >= $limit) {
				throw new ConflictException('device_limit_reached');
			}
			$code = $this->generatePairCode();
			$hash = $this->hashSecret($code);
			$now = $this->clock->now();
			$device = new ScanDevice();
			$device->setLabel($label);
			$device->setPairCodeHash($hash);
			$device->setPairCodeExpires($now + 86400);
			$device->setTokenHash(null);
			$device->setPairedAt(null);
			$device->setLastSeenAt(null);
			$device->setActive(true);
			$device->setCreatedAt($now);
			$device->setCreatedBy($adminUid);
			$device = $this->devices->insert($device);
			return ['device' => $device->toApi(), 'pairCode' => $code];
		});
	}

	/** @return array{data: list<array<string, mixed>>, total: int, limit: int, offset: int} */
	public function listDevices(int $limit, int $offset): array
	{
		$all = $this->devices->findAll();
		$rows = array_map(static fn (ScanDevice $d) => $d->toApi(), $all);
		return [
			'data' => array_slice($rows, $offset, $limit),
			'total' => count($rows),
			'limit' => $limit,
			'offset' => $offset,
		];
	}

	/**
	 * Soft-unpair: keep token_hash so the next device call is identifiable as
	 * this slot and returns 402 `device_required` (SPEC §9.3.4). Pairing codes
	 * are cleared. Regenerating a code clears the token separately.
	 */
	public function deactivateDevice(int $id): void
	{
		$device = $this->devices->findById($id);
		$device->setActive(false);
		$device->setPairCodeHash(null);
		$device->setPairCodeExpires(null);
		$this->devices->update($device);
	}

	/**
	 * Rotate a one-time pairing code on an existing active slot (§9.3.4).
	 * Clears any prior token so the device must re-pair.
	 *
	 * @return array{device: array<string, mixed>, pairCode: string}
	 */
	public function regeneratePairCode(string $adminUid, int $id): array
	{
		unset($adminUid);
		$device = $this->devices->findById($id);
		if (!$device->getActive()) {
			throw new NotFoundException();
		}
		$code = $this->generatePairCode();
		$now = $this->clock->now();
		$rotated = $this->devices->rotatePairCode(
			$id,
			$this->hashSecret($code),
			$now + 86400,
		);
		if (!$rotated) {
			throw new NotFoundException();
		}
		$device = $this->devices->findById($id);
		return ['device' => $device->toApi(), 'pairCode' => $code];
	}

	public function findSingletonState(): ?LicenseState
	{
		return $this->licenseState->findSingleton();
	}

	public function findSeatByUid(string $uid): ?MobileSeat
	{
		return $this->seats->findByUid($uid);
	}

	/** @return list<MobileSeat> */
	public function allSeats(): array
	{
		return $this->seats->findAllRanked();
	}

	/** @return list<ScanDevice> */
	public function pairedActiveDevices(): array
	{
		return $this->devices->findPairedActive();
	}

	public function findDeviceByTokenHash(string $hash): ?ScanDevice
	{
		return $this->devices->findByTokenHash($hash);
	}

	/**
	 * Hash pairing codes and device tokens (N5).
	 * HMAC-SHA256 with the instance secret so short pair codes are not
	 * offline-crackable from a leaked iv_scan_devices dump alone.
	 *
	 * Fail closed if the instance secret is missing — a static fallback pepper
	 * would make dump-only cracking trivial again.
	 */
	public function hashSecret(string $secret): string
	{
		$pepper = $this->config->getSystemValueString('secret', '');
		if ($pepper === '') {
			throw new \RuntimeException('instance_secret_missing');
		}
		return hash_hmac('sha256', $secret, $pepper);
	}

	/** @return array<string, mixed> */
	private function seatRow(MobileSeat $seat): array
	{
		$limit = $this->licenseState->findSingleton()?->getMobileSeats() ?? 0;
		$rankInput = array_map(
			static fn (MobileSeat $s): array => ['id' => (int)$s->getId(), 'assignedAt' => $s->getAssignedAt()],
			$this->seats->findAllRanked(),
		);
		return [
			'uid' => $seat->getUid(),
			'displayName' => $this->userManager->get($seat->getUid())?->getDisplayName() ?? $seat->getUid(),
			'assignedAt' => $seat->getAssignedAt(),
			'assignedBy' => $seat->getAssignedBy(),
			'withinLimit' => SeatRank::isWithinLimit($rankInput, (int)$seat->getId(), $limit),
		];
	}

	private function generatePairCode(): string
	{
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$code = '';
		for ($i = 0; $i < 8; $i++) {
			$code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
		}
		return $code;
	}

	/**
	 * Serialize seat/device capacity checks (AC-16 TOCTOU).
	 *
	 * @template T
	 * @param callable(): T $fn
	 * @return T
	 */
	private function withExclusiveLock(string $key, string $busyCode, callable $fn): mixed
	{
		$attempts = 0;
		while (true) {
			try {
				$this->locking->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE);
				break;
			} catch (LockedException) {
				if (++$attempts >= 40) {
					throw new ConflictException($busyCode);
				}
				usleep(25_000);
			}
		}
		try {
			return $fn();
		} finally {
			$this->locking->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
}
