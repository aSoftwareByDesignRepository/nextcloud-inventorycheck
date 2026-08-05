<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCA\InventoryCheck\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Item>
 */
class ItemMapper extends QBMapper
{
	use RowLocking;

	public const TABLE = 'iv_items';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, Item::class);
	}

	/**
	 * Locking read (see RowLocking protocol). Must run inside a transaction.
	 */
	public function lockById(int $id, bool $exclusive): Item
	{
		$row = $this->selectRowByIdLocked($id, $exclusive);
		if ($row === null) {
			throw new NotFoundException('unknown_item');
		}
		/** @var Item */
		return $this->mapRowToEntity($row);
	}

	public function findById(int $id): Item
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			throw new NotFoundException('unknown_item');
		}
	}

	public function findByScanCode(string $code): ?Item
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('scan_code', $qb->createNamedParameter($code)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function findBySku(string $sku): ?Item
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('sku', $qb->createNamedParameter($sku)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * S8: scan_code first, then sku. Inactive → treat as miss (caller maps 404).
	 */
	public function resolveByCode(string $code): ?Item
	{
		$byScan = $this->findByScanCode($code);
		if ($byScan !== null) {
			return $byScan->getActive() ? $byScan : null;
		}
		$bySku = $this->findBySku($code);
		if ($bySku !== null) {
			return $bySku->getActive() ? $bySku : null;
		}
		return null;
	}

	/**
	 * @return list<array{id: int, sku: string, scanCode: string}>
	 */
	public function allCodePairs(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'sku', 'scan_code')->from($this->getTableName());
		$result = $qb->executeQuery();
		$rows = [];
		while ($row = $result->fetch()) {
			$rows[] = [
				'id' => (int)$row['id'],
				'sku' => (string)$row['sku'],
				'scanCode' => (string)$row['scan_code'],
			];
		}
		$result->closeCursor();
		return $rows;
	}

	/**
	 * @param list<int>|null $idFilter restrict to these ids (null = no filter)
	 * @return array{data: list<Item>, total: int}
	 */
	public function search(string $q, ?bool $active, int $limit, int $offset, ?array $idFilter = null): array
	{
		if ($idFilter === []) {
			return ['data' => [], 'total' => 0];
		}
		$apply = function ($qb) use ($q, $active, $idFilter): void {
			$qb->from($this->getTableName());
			$conds = [];
			if ($idFilter !== null) {
				$conds[] = $qb->expr()->in(
					'id',
					$qb->createNamedParameter($idFilter, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY),
				);
			}
			if ($active !== null) {
				$conds[] = $qb->expr()->eq('active', $qb->createNamedParameter($active, \PDO::PARAM_BOOL));
			}
			if ($q !== '') {
				$lower = '%' . $this->db->escapeLikeParameter(mb_strtolower($q)) . '%';
				$prefix = $this->db->escapeLikeParameter($q) . '%';
				$conds[] = $qb->expr()->orX(
					$qb->expr()->like($qb->func()->lower('name'), $qb->createNamedParameter($lower)),
					$qb->expr()->like('sku', $qb->createNamedParameter($prefix)),
				);
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
		$qb->orderBy('name', 'ASC')->addOrderBy('id', 'ASC')
			->setMaxResults($limit)->setFirstResult($offset);

		return ['data' => $this->findEntities($qb), 'total' => $total];
	}

	/**
	 * Keyset page of active items by ascending id (phantom-safe inventur create).
	 * Offset pages can re-emit the same id when concurrent inserts shift name order.
	 *
	 * @return list<\OCA\InventoryCheck\Db\Item>
	 */
	public function searchActiveAfterId(int $afterId, int $limit): array
	{
		if ($limit < 1) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('active', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)))
			->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($afterId, \PDO::PARAM_INT)))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	public function countMovementsReferencing(int $itemId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'cnt'))
			->from('iv_movements')
			->where($qb->expr()->eq('item_id', $qb->createNamedParameter($itemId, \PDO::PARAM_INT)));
		$result = $qb->executeQuery();
		$total = (int)($result->fetchOne() ?: 0);
		$result->closeCursor();
		return $total;
	}

	public function hasNonZeroBalance(int $itemId): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('iv_balances')
			->where($qb->expr()->eq('item_id', $qb->createNamedParameter($itemId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->neq('qty', $qb->createNamedParameter(0, \PDO::PARAM_INT)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row !== false;
	}
}
