<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCA\InventoryCheck\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Location>
 */
class LocationMapper extends QBMapper
{
	use RowLocking;

	public const TABLE = 'iv_locations';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, Location::class);
	}

	/**
	 * Locking read (see RowLocking protocol). Must run inside a transaction.
	 */
	public function lockById(int $id, bool $exclusive): Location
	{
		$row = $this->selectRowByIdLocked($id, $exclusive);
		if ($row === null) {
			throw new NotFoundException('unknown_location');
		}
		/** @var Location */
		return $this->mapRowToEntity($row);
	}

	public function findById(int $id): Location
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			throw new NotFoundException('unknown_location');
		}
	}

	public function findByCode(string $code): ?Location
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('code', $qb->createNamedParameter($code)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * @return array{data: list<Location>, total: int}
	 */
	public function search(?bool $active, int $limit, int $offset): array
	{
		$apply = function ($qb) use ($active): void {
			$qb->from($this->getTableName());
			if ($active !== null) {
				$qb->where($qb->expr()->eq('active', $qb->createNamedParameter($active, \PDO::PARAM_BOOL)));
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
		$qb->orderBy('name', 'ASC')->addOrderBy('id', 'ASC')
			->setMaxResults($limit)->setFirstResult($offset);

		return ['data' => $this->findEntities($qb), 'total' => $total];
	}

	public function countMovementsReferencing(int $locationId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'cnt'))
			->from('iv_movements')
			->where($qb->expr()->orX(
				$qb->expr()->eq('location_id', $qb->createNamedParameter($locationId, \PDO::PARAM_INT)),
				$qb->expr()->eq('counterparty_loc_id', $qb->createNamedParameter($locationId, \PDO::PARAM_INT)),
			));
		$result = $qb->executeQuery();
		$total = (int)($result->fetchOne() ?: 0);
		$result->closeCursor();
		return $total;
	}

	public function hasNonZeroBalance(int $locationId): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('iv_balances')
			->where($qb->expr()->eq('location_id', $qb->createNamedParameter($locationId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->neq('qty', $qb->createNamedParameter(0, \PDO::PARAM_INT)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row !== false;
	}
}
