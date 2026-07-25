<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Db\Balance;
use OCA\InventoryCheck\Db\BalanceMapper;

class BalanceService
{
	public function __construct(
		private readonly BalanceMapper $balances,
	) {
	}

	/** @return array{data: list<array<string, mixed>>, total: int, limit: int, offset: int} */
	public function list(
		?int $itemId,
		?int $locationId,
		bool $nonZero,
		int $limit,
		int $offset,
		bool $negativeOnly = false,
	): array {
		$result = $this->balances->search($itemId, $locationId, $nonZero, $limit, $offset, $negativeOnly);
		return [
			'data' => array_map(static fn (Balance $b) => $b->toApi(), $result['data']),
			'total' => $result['total'],
			'limit' => $limit,
			'offset' => $offset,
		];
	}
}
