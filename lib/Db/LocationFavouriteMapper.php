<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<LocationFavourite>
 */
class LocationFavouriteMapper extends QBMapper
{
	public const TABLE = 'iv_loc_fav';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, LocationFavourite::class);
	}

	/** @return list<int> */
	public function locationIdsForUser(string $userId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('location_id')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('created_at', 'ASC')
			->addOrderBy('id', 'ASC');
		$res = $qb->executeQuery();
		$out = [];
		while (($row = $res->fetch()) !== false) {
			$out[] = (int)$row['location_id'];
		}
		$res->closeCursor();
		return $out;
	}

	public function countForUser(string $userId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'cnt'))->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$res = $qb->executeQuery();
		$total = (int)($res->fetchOne() ?: 0);
		$res->closeCursor();
		return $total;
	}

	public function findPair(string $userId, int $locationId): ?LocationFavourite
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('location_id', $qb->createNamedParameter($locationId, IQueryBuilder::PARAM_INT)));
		$entities = $this->findEntities($qb);
		return $entities[0] ?? null;
	}

	public function deletePair(string $userId, int $locationId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('location_id', $qb->createNamedParameter($locationId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/** GDPR / user-delete: drop every favourite row for a deleted UID. */
	public function deleteAllForUser(string $userId): void
	{
		if ($userId === '') {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}
}
