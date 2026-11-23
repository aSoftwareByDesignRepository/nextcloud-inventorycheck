<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<Person> */
class PersonMapper extends QBMapper
{
	public const TABLE = 'iv_persons';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, Person::class);
	}

	public function findById(int $id): Person
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		return $this->findEntity($qb);
	}

	/** @return list<Person> substring match on name/member_no, active rows first */
	public function search(string $term, int $limit = 20): array
	{
		$qb = $this->db->getQueryBuilder();
		$like = '%' . $this->db->escapeLikeParameter($term) . '%';
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('anonymized', $qb->createNamedParameter(0)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->iLike('display_name', $qb->createNamedParameter($like)),
				$qb->expr()->iLike('member_no', $qb->createNamedParameter($like)),
			))
			->orderBy('active', 'DESC')
			->addOrderBy('display_name', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/** Register list: all persons (anonymized included), name-ordered, paged. */
	public function list(int $limit = 50, int $offset = 0): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('display_name', 'ASC')
			->setMaxResults($limit)
			->setFirstResult($offset);
		return $this->findEntities($qb);
	}

	public function findByNcUserId(string $uid): ?Person
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('nc_user_id', $qb->createNamedParameter($uid)))
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** GDPR erasure support: unlink a deleted NC user from any person rows. */
	public function unlinkNcUser(string $uid): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('nc_user_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('nc_user_id', $qb->createNamedParameter($uid)));
		return $qb->executeStatement();
	}
}
