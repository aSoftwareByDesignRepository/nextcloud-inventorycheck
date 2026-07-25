<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Movement>
 */
class MovementMapper extends QBMapper
{
	public const TABLE = 'iv_movements';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, Movement::class);
	}

	/**
	 * @return array{data: list<Movement>, total: int}
	 */
	public function search(
		?string $kind,
		?int $itemId,
		?int $locationId,
		?int $from,
		?int $to,
		?string $transferGroup,
		int $limit,
		int $offset,
	): array {
		$apply = function ($qb) use ($kind, $itemId, $locationId, $from, $to, $transferGroup): void {
			$qb->from($this->getTableName());
			$conds = [];
			if ($kind !== null && $kind !== '') {
				$conds[] = $qb->expr()->eq('kind', $qb->createNamedParameter($kind));
			}
			if ($itemId !== null) {
				$conds[] = $qb->expr()->eq('item_id', $qb->createNamedParameter($itemId, \PDO::PARAM_INT));
			}
			if ($locationId !== null) {
				$conds[] = $qb->expr()->eq('location_id', $qb->createNamedParameter($locationId, \PDO::PARAM_INT));
			}
			if ($from !== null) {
				$conds[] = $qb->expr()->gte('created_at', $qb->createNamedParameter($from, \PDO::PARAM_INT));
			}
			if ($to !== null) {
				$conds[] = $qb->expr()->lte('created_at', $qb->createNamedParameter($to, \PDO::PARAM_INT));
			}
			if ($transferGroup !== null && $transferGroup !== '') {
				$conds[] = $qb->expr()->eq('transfer_group', $qb->createNamedParameter($transferGroup));
			}
			if ($conds !== []) {
				$qb->where(...$conds);
			}
		};

		$countQb = $this->db->getQueryBuilder();
		$countQb->select($countQb->func()->count('id', 'cnt'));
		$apply($countQb);
		$result = $countQb->executeQuery();
		$total = (int)($result->fetchOne() ?: 0);
		$result->closeCursor();

		$qb = $this->db->getQueryBuilder();
		$qb->select('*');
		$apply($qb);
		$qb->orderBy('created_at', 'DESC')->addOrderBy('id', 'DESC')
			->setMaxResults($limit)->setFirstResult($offset);

		return ['data' => $this->findEntities($qb), 'total' => $total];
	}

	/**
	 * @return array<string, int> "itemId:locationId" → SUM(qty_delta)
	 */
	public function sumDeltasByPair(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('item_id', 'location_id')
			->selectAlias($qb->func()->sum('qty_delta'), 'total')
			->from($this->getTableName())
			->groupBy('item_id')
			->addGroupBy('location_id');
		$result = $qb->executeQuery();
		$out = [];
		while ($row = $result->fetch()) {
			$key = (int)$row['item_id'] . ':' . (int)$row['location_id'];
			$out[$key] = (int)$row['total'];
		}
		$result->closeCursor();
		return $out;
	}
}
