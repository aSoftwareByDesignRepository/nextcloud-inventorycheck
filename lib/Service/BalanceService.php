<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Db\Balance;
use OCA\InventoryCheck\Db\BalanceMapper;

class BalanceService
{
	public function __construct(
		private readonly BalanceMapper $balances,
		private readonly LocationAclService $locationAcl,
	) {
	}

	/**
	 * C3: field users only see balances for locations they're granted
	 * (directly or via group) — see {@see LocationAclService::visibleLocationIds}.
	 *
	 * @return array{data: list<array<string, mixed>>, total: int, limit: int, offset: int}
	 */
	public function list(
		string $actorUid,
		?int $itemId,
		?int $locationId,
		bool $nonZero,
		int $limit,
		int $offset,
		bool $negativeOnly = false,
	): array {
		$visible = $this->locationAcl->visibleLocationIds($actorUid);
		if ($visible !== null && $locationId !== null && !in_array($locationId, $visible, true)) {
			// Explicit location filter the caller cannot see → empty result,
			// not an error (consistent with the ACL being a visibility
			// filter rather than a hard per-request authorization gate here).
			return ['data' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset];
		}
		$result = $this->balances->search($itemId, $locationId, $nonZero, $limit, $offset, $negativeOnly, $visible);
		return [
			'data' => array_map(static fn (Balance $b) => $b->toApi(), $result['data']),
			'total' => $result['total'],
			'limit' => $limit,
			'offset' => $offset,
		];
	}
}
