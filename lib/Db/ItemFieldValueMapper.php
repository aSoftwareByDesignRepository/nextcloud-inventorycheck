<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * IV-CUSTOM-FIELDS: composite-PK value store — raw QB (no QBMapper:
 * there is no surrogate id, so Entity id-mapping does not apply).
 */
class ItemFieldValueMapper
{
	public const TABLE = 'iv_item_field_values';

	public function __construct(
		protected readonly IDBConnection $db,
	) {
	}

	/** @return array<int, ?string> field_id → raw value for one item */
	public function valuesForItem(int $itemId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('field_id', 'value')->from(self::TABLE)
			->where($qb->expr()->eq('item_id', $qb->createNamedParameter($itemId, \PDO::PARAM_INT)));
		$res = $qb->executeQuery();
		$out = [];
		while (($row = $res->fetch()) !== false) {
			$out[(int)$row['field_id']] = $row['value'];
		}
		$res->closeCursor();
		return $out;
	}

	/** @param list<int> $itemIds @return array<int, array<int, ?string>> item_id → (field_id → value) */
	public function valuesForItems(array $itemIds): array
	{
		if ($itemIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('item_id', 'field_id', 'value')->from(self::TABLE)
			->where($qb->expr()->in('item_id', $qb->createNamedParameter(array_values(array_unique($itemIds)), IQueryBuilder::PARAM_INT_ARRAY)));
		$res = $qb->executeQuery();
		$out = [];
		while (($row = $res->fetch()) !== false) {
			$out[(int)$row['item_id']][(int)$row['field_id']] = $row['value'];
		}
		$res->closeCursor();
		return $out;
	}

	/** Portable upsert: update first, insert when absent; retry-on-PK-race. */
	public function set(int $itemId, int $fieldId, ?string $value): void
	{
		if ($value === null) {
			$this->delete($itemId, $fieldId);
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('value', $qb->createNamedParameter($value))
			->where($qb->expr()->eq('item_id', $qb->createNamedParameter($itemId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('field_id', $qb->createNamedParameter($fieldId, \PDO::PARAM_INT)));
		if ($qb->executeStatement() === 1) {
			return;
		}
		try {
			$ins = $this->db->getQueryBuilder();
			$ins->insert(self::TABLE)
				->setValue('item_id', $ins->createNamedParameter($itemId, \PDO::PARAM_INT))
				->setValue('field_id', $ins->createNamedParameter($fieldId, \PDO::PARAM_INT))
				->setValue('value', $ins->createNamedParameter($value));
			$ins->executeStatement();
		} catch (\Throwable) {
			// Concurrent insert won the PK race — fall back to update.
			$qb = $this->db->getQueryBuilder();
			$qb->update(self::TABLE)
				->set('value', $qb->createNamedParameter($value))
				->where($qb->expr()->eq('item_id', $qb->createNamedParameter($itemId, \PDO::PARAM_INT)))
				->andWhere($qb->expr()->eq('field_id', $qb->createNamedParameter($fieldId, \PDO::PARAM_INT)));
			$qb->executeStatement();
		}
	}

	public function delete(int $itemId, int $fieldId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('item_id', $qb->createNamedParameter($itemId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('field_id', $qb->createNamedParameter($fieldId, \PDO::PARAM_INT)));
		$qb->executeStatement();
	}

	public function deleteForField(int $fieldId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('field_id', $qb->createNamedParameter($fieldId, \PDO::PARAM_INT)));
		return $qb->executeStatement();
	}

	public function deleteForItem(int $itemId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('item_id', $qb->createNamedParameter($itemId, \PDO::PARAM_INT)));
		$qb->executeStatement();
	}

	public function countForField(int $fieldId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from(self::TABLE)
			->where($qb->expr()->eq('field_id', $qb->createNamedParameter($fieldId, \PDO::PARAM_INT)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/**
	 * AC-9 / NN-04: EXISTS fragment for `ItemMapper::search`'s `q` clause —
	 * built with the PARENT query builder's named parameters so embedded
	 * SQL never collides with the outer `:dcValueN` numbering (the F4
	 * collision bug). Returns null when no searchable fields exist.
	 *
	 * @param IQueryBuilder $qb the parent's builder (params are bound here)
	 * @param list<int> $fieldIds text/select def ids only
	 */
	public function valueMatchExistsSql(IQueryBuilder $qb, string $itemIdColumn, string $term, array $fieldIds): ?string
	{
		if ($fieldIds === []) {
			return null;
		}
		$fieldParam = $qb->createNamedParameter($fieldIds, IQueryBuilder::PARAM_INT_ARRAY);
		$likeParam = $qb->createNamedParameter('%' . addcslashes($term, '\\%_') . '%');
		return 'EXISTS (SELECT 1 FROM *PREFIX*' . self::TABLE . ' ivcfv '
			. 'WHERE ivcfv.item_id = ' . $itemIdColumn . ' '
			. 'AND ivcfv.field_id IN (' . $fieldParam . ') '
			. 'AND ivcfv.value LIKE ' . $likeParam . ')';
	}
}
