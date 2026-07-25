<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

/**
 * SPEC §8.4 seat rank — within-limit iff rank by (assigned_at ASC, id ASC) ≤ limit.
 */
class SeatRank
{
	/**
	 * @param list<array{id: int, assignedAt: int}> $seats
	 * @return array<int, int> seat id → 1-based rank
	 */
	public static function ranks(array $seats): array
	{
		usort($seats, static function (array $a, array $b): int {
			if ($a['assignedAt'] !== $b['assignedAt']) {
				return $a['assignedAt'] <=> $b['assignedAt'];
			}
			return $a['id'] <=> $b['id'];
		});
		$ranks = [];
		$rank = 1;
		foreach ($seats as $seat) {
			$ranks[$seat['id']] = $rank;
			$rank++;
		}
		return $ranks;
	}

	/**
	 * @param list<array{id: int, assignedAt: int}> $seats
	 */
	public static function isWithinLimit(array $seats, int $seatId, int $limit): bool
	{
		if ($limit <= 0) {
			return false;
		}
		$ranks = self::ranks($seats);
		if (!isset($ranks[$seatId])) {
			return false;
		}
		return $ranks[$seatId] <= $limit;
	}
}
