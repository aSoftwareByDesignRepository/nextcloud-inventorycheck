<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCA\InventoryCheck\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<ItemField>
 */
class ItemFieldMapper extends QBMapper
{
	public const TABLE = 'iv_item_fields';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, ItemField::class);
	}

	public function findById(int $id): ItemField
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			throw new NotFoundException('unknown_field');
		}
	}

	/** @return list<ItemField> all defs ordered for display (active first) */
	public function all(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('position', 'ASC')->addOrderBy('name', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<ItemField> active defs in dialog/detail order */
	public function allActive(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('active', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)))
			->orderBy('position', 'ASC')->addOrderBy('name', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Active-def name collision, case-insensitive. Excludes $excludeId on
	 * rename checks (a def must not collide with itself).
	 */
	public function activeNameExists(string $name, ?int $excludeId = null): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from($this->getTableName())
			->where($qb->expr()->eq('active', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)))
			->andWhere($qb->expr()->eq(
				$qb->func()->lower('name'),
				$qb->createNamedParameter(mb_strtolower($name)),
			))
			->setMaxResults(1);
		if ($excludeId !== null) {
			$qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($excludeId, \PDO::PARAM_INT)));
		}
		$res = $qb->executeQuery();
		$found = $res->fetch() !== false;
		$res->closeCursor();
		return $found;
	}

	/** Next free position slot (max+10 — gaps allow reorder inserts). */
	public function nextPosition(): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('position'))->from($this->getTableName());
		$max = $qb->executeQuery()->fetchOne();
		return $max === false ? 10 : (int)$max + 10;
	}

	/** @param list<int> $ids @return array<int, ItemField> */
	public function byIds(array $ids): array
	{
		if ($ids === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->in('id', $qb->createNamedParameter(array_values(array_unique($ids)), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
		$out = [];
		foreach ($this->findEntities($qb) as $f) {
			$out[(int)$f->getId()] = $f;
		}
		return $out;
	}
}
