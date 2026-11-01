<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCA\InventoryCheck\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<OrderSheet>
 */
class OrderSheetMapper extends QBMapper
{
	public const TABLE = 'iv_order_sheets';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, OrderSheet::class);
	}

	public function findById(int $id): OrderSheet
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			throw new NotFoundException('unknown_order_sheet');
		}
	}

	/** @return list<OrderSheet> */
	public function search(?string $status, int $limit, int $offset): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('id', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);
		if ($status !== null && $status !== '') {
			// Comma-separated allowlist ('draft,sent') — unknown tokens match nothing.
			$states = array_values(array_filter(array_map(
				static fn (string $s): string => trim($s),
				explode(',', $status),
			), static fn (string $s): bool => in_array($s, [
				OrderSheet::STATUS_DRAFT, OrderSheet::STATUS_SENT,
				OrderSheet::STATUS_RECEIVED, OrderSheet::STATUS_CANCELLED,
			], true)));
			if ($states === []) {
				return [];
			}
			$qb->where($qb->expr()->in('status', $qb->createNamedParameter($states, IQueryBuilder::PARAM_STR_ARRAY)));
		}
		return $this->findEntities($qb);
	}

	/** Open (sent) sheet ids that carry a line for this item — for receive auto-progress. */
	public function openSheetIdsForItem(int $itemId, int $limit = 50): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('sheet_id')->from('iv_order_sheet_lines', 'l')
			->innerJoin('l', 'iv_order_sheets', 's', 's.id = l.sheet_id')
			->where($qb->expr()->eq('l.item_id', $qb->createNamedParameter($itemId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('s.status', $qb->createNamedParameter(OrderSheet::STATUS_SENT)))
			->setMaxResults($limit);
		$res = $qb->executeQuery();
		$out = [];
		while ($row = $res->fetch()) {
			$out[] = (int)$row['sheet_id'];
		}
		$res->closeCursor();
		return $out;
	}

	public function setStatus(int $id, string $status, ?int $timestamp): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter($status));
		$col = match ($status) {
			OrderSheet::STATUS_SENT => 'sent_at',
			OrderSheet::STATUS_RECEIVED => 'received_at',
			OrderSheet::STATUS_CANCELLED => 'cancelled_at',
			default => null,
		};
		if ($col !== null) {
			$qb->set($col, $qb->createNamedParameter($timestamp, \PDO::PARAM_INT));
		}
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)));
		$qb->executeStatement();
	}
}
