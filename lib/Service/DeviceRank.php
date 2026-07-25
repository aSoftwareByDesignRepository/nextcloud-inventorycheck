<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

/**
 * SPEC §9.1 rung 6 — device rank by (paired_at ASC, id ASC) ≤ scan_devices.
 * Unpaired devices (paired_at null) sort after all paired ones for ranking
 * of *active paired* slots; callers pass only paired rows.
 */
class DeviceRank
{
	/**
	 * @param list<array{id: int, pairedAt: int}> $devices
	 * @return array<int, int> device id → 1-based rank
	 */
	public static function ranks(array $devices): array
	{
		usort($devices, static function (array $a, array $b): int {
			if ($a['pairedAt'] !== $b['pairedAt']) {
				return $a['pairedAt'] <=> $b['pairedAt'];
			}
			return $a['id'] <=> $b['id'];
		});
		$ranks = [];
		$rank = 1;
		foreach ($devices as $device) {
			$ranks[$device['id']] = $rank;
			$rank++;
		}
		return $ranks;
	}

	/**
	 * @param list<array{id: int, pairedAt: int}> $devices
	 */
	public static function isWithinLimit(array $devices, int $deviceId, int $limit): bool
	{
		if ($limit <= 0) {
			return false;
		}
		$ranks = self::ranks($devices);
		if (!isset($ranks[$deviceId])) {
			return false;
		}
		return $ranks[$deviceId] <= $limit;
	}
}
