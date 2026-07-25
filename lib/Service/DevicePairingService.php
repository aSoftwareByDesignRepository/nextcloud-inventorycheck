<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Db\ScanDevice;
use OCA\InventoryCheck\Db\ScanDeviceMapper;
use OCA\InventoryCheck\Exception\MobileGateException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Device pairing (SPEC §9.3) + rate limit on failed codes.
 *
 * Pair claim is atomic: UPDATE … WHERE pair_code_hash still matches so two
 * concurrent POSTs cannot both receive a live token (AC-18 / N5).
 * Rate-limit check+increment share one exclusive lock so concurrent bad
 * attempts cannot all pass a stale count and overshoot RATE_MAX (TOCTOU).
 */
class DevicePairingService
{
	private const RATE_KEY = 'pair_fail_window';
	private const RATE_MAX = 10;
	private const RATE_WINDOW = 3600;
	private const LAST_SEEN_THROTTLE = 300;
	private const RATE_LOCK = 'inventorycheck/pair_rate';

	public function __construct(
		private readonly LicenseService $license,
		private readonly ScanDeviceMapper $devices,
		private readonly Clock $clock,
		private readonly IConfig $config,
		private readonly ILockingProvider $locking,
	) {
	}

	/**
	 * @return array{token: string, deviceId: int}
	 */
	public function pair(string $code): array
	{
		// SPEC AC-18: refuse before format work when the window is already full
		// so the 11th bad attempt returns 429 (not another 422).
		$this->assertNotRateLimited();

		$code = strtoupper(CodeRules::trim($code));
		if ($code === '' || !preg_match('/^[A-Z2-9]{8}$/', $code)) {
			$this->recordFailureOrThrowRateLimited();
			throw new ValidationException('invalid_pair_code');
		}

		$hash = $this->license->hashSecret($code);
		$now = $this->clock->now();
		$match = $this->devices->findPendingByPairCodeHash($hash);
		if ($match === null || !$match->getActive()) {
			$this->recordFailureOrThrowRateLimited();
			throw new ValidationException('invalid_pair_code');
		}
		$expires = $match->getPairCodeExpires();
		if ($expires === null || $expires < $now) {
			$this->recordFailureOrThrowRateLimited();
			throw new ValidationException('invalid_pair_code');
		}

		$token = bin2hex(random_bytes(32));
		$tokenHash = $this->license->hashSecret($token);
		$claimed = $this->devices->claimPairing(
			(int)$match->getId(),
			$hash,
			$tokenHash,
			$now,
		);
		if (!$claimed) {
			$this->recordFailureOrThrowRateLimited();
			throw new ValidationException('invalid_pair_code');
		}

		return ['token' => $token, 'deviceId' => (int)$match->getId()];
	}

	/**
	 * SPEC §9.3: update last_seen_at at most once per 5 minutes.
	 */
	public function touchLastSeen(ScanDevice $device): void
	{
		$now = $this->clock->now();
		$last = $device->getLastSeenAt() ?? 0;
		if ($now - $last < self::LAST_SEEN_THROTTLE) {
			return;
		}
		$device->setLastSeenAt($now);
		$this->devices->update($device);
	}

	private function assertNotRateLimited(): void
	{
		$this->withRateLock(function (): void {
			if ($this->currentFailureCount() >= self::RATE_MAX) {
				throw new MobileGateException('rate_limited');
			}
		});
	}

	/**
	 * Atomically re-check the window and increment. Concurrent losers that
	 * passed a stale assertNotRateLimited() get 429 instead of bumping past RATE_MAX.
	 */
	private function recordFailureOrThrowRateLimited(): void
	{
		$this->withRateLock(function (): void {
			$now = $this->clock->now();
			$raw = $this->config->getAppValue(Application::APP_ID, self::RATE_KEY, '');
			$data = $raw !== '' ? json_decode($raw, true) : null;
			if (!is_array($data) || $now - (int)($data['start'] ?? 0) > self::RATE_WINDOW) {
				$data = ['start' => $now, 'count' => 0];
			}
			$count = (int)($data['count'] ?? 0);
			if ($count >= self::RATE_MAX) {
				throw new MobileGateException('rate_limited');
			}
			$data['count'] = $count + 1;
			$this->config->setAppValue(Application::APP_ID, self::RATE_KEY, json_encode($data));
		});
	}

	private function currentFailureCount(): int
	{
		$raw = $this->config->getAppValue(Application::APP_ID, self::RATE_KEY, '');
		$data = $raw !== '' ? json_decode($raw, true) : null;
		if (!is_array($data)) {
			return 0;
		}
		$windowStart = (int)($data['start'] ?? 0);
		$count = (int)($data['count'] ?? 0);
		$now = $this->clock->now();
		if ($now - $windowStart > self::RATE_WINDOW) {
			return 0;
		}
		return $count;
	}

	/**
	 * @param callable(): void $fn
	 */
	private function withRateLock(callable $fn): void
	{
		$attempts = 0;
		while (true) {
			try {
				$this->locking->acquireLock(self::RATE_LOCK, ILockingProvider::LOCK_EXCLUSIVE);
				break;
			} catch (LockedException) {
				// Contended lock ≠ rate limit; retry briefly so honest clients are not 429'd.
				if (++$attempts >= 40) {
					throw new MobileGateException('rate_limited');
				}
				usleep(25_000);
			}
		}
		try {
			$fn();
		} finally {
			$this->locking->releaseLock(self::RATE_LOCK, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
}
