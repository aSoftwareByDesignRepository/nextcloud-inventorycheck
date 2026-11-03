<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<OrderSheetLine>
 */
class OrderSheetLineMapper extends QBMapper
{
	public const TABLE = 'iv_order_sheet_lines';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, OrderSheetLine::class);
	}

	/** @return list<OrderSheetLine> */
	public function findBySheet(int $sheetId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('sheet_id', $qb->createNamedParameter($sheetId, \PDO::PARAM_INT)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** replaceLines: drop a draft's lines before re-inserting (draft only). */
	public function deleteBySheet(int $sheetId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('sheet_id', $qb->createNamedParameter($sheetId, \PDO::PARAM_INT)));
		$qb->executeStatement();
	}

	/** F-13: a receive of qty against item covers open lines oldest-first. */
	public function decrementLine(int $lineId, int $qty): void
	{
		$qb = $this->db->getQueryBuilder();
		$p = $qb->createNamedParameter($qty, \PDO::PARAM_INT);
		$qb->update($this->getTableName())
			->set('received_qty', $qb->createFunction('CASE WHEN received_qty + ' . $p . ' > qty THEN qty ELSE received_qty + ' . $p . ' END'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($lineId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->lt('received_qty', 'qty'));
		$qb->executeStatement();
	}

	/** @return list<array{id:int, item_id:int, qty:int, received_qty:int}> */
	public function openLinesForItem(int $itemId, int $sheetId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'item_id', 'qty', 'received_qty')->from($this->getTableName())
			->where($qb->expr()->eq('item_id', $qb->createNamedParameter($itemId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('sheet_id', $qb->createNamedParameter($sheetId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->lt('received_qty', 'qty'))
			->orderBy('id', 'ASC');
		$res = $qb->executeQuery();
		$out = [];
		while ($row = $res->fetch()) {
			$out[] = ['id' => (int)$row['id'], 'item_id' => (int)$row['item_id'], 'qty' => (int)$row['qty'], 'received_qty' => (int)$row['received_qty']];
		}
		$res->closeCursor();
		return $out;
	}

	public function allLinesReceived(int $sheetId): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from($this->getTableName())
			->where($qb->expr()->eq('sheet_id', $qb->createNamedParameter($sheetId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->lt('received_qty', 'qty'))
			->setMaxResults(1);
		$res = $qb->executeQuery();
		$open = $res->fetch();
		$res->closeCursor();
		return $open === false;
	}
}
