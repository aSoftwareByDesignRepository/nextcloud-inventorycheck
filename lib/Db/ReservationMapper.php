<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<Reservation> */
class ReservationMapper extends QBMapper
{
	public const TABLE = 'iv_reservations';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, Reservation::class);
	}

	public function findById(int $id): Reservation
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		/** @var Reservation */
		return $this->findEntity($qb);
	}

	public function findByIdForUpdate(int $id): Reservation
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->forUpdate();
		/** @var Reservation */
		return $this->findEntity($qb);
	}

	/** Open reservations holding stock at an item/location. @return list<Reservation> */
	public function openForItemLocation(int $itemId, int $locationId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('item_id', $qb->createNamedParameter($itemId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('location_id', $qb->createNamedParameter($locationId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('reserved')));
		/** @var list<Reservation> */
		return $this->findEntities($qb);
	}

	/** Reserved qty overlapping a window (availability math). */
	public function reservedQtyInWindow(int $itemId, int $locationId, int $now, ?int $until = null): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->sum('qty'))->from(self::TABLE)
			->where($qb->expr()->eq('item_id', $qb->createNamedParameter($itemId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('location_id', $qb->createNamedParameter($locationId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('reserved')))
			->andWhere($qb->expr()->gte('to_ts', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));
		if ($until !== null) {
			$qb->andWhere($qb->expr()->lte('from_ts', $qb->createNamedParameter($until, IQueryBuilder::PARAM_INT)));
		}
		$v = $qb->executeQuery()->fetchOne();
		return $v === false || $v === null ? 0 : (int)$v;
	}

	/** Reserved qty for an item across all locations (portal badge). */
	public function reservedQtyForItem(int $itemId, int $now): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->sum('qty'))->from(self::TABLE)
			->where($qb->expr()->eq('item_id', $qb->createNamedParameter($itemId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('reserved')))
			->andWhere($qb->expr()->gte('to_ts', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));
		$v = $qb->executeQuery()->fetchOne();
		return $v === false || $v === null ? 0 : (int)$v;
	}

	/** @return list<Reservation> */
	public function listForItem(int $itemId, int $limit = 200, int $offset = 0): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('item_id', $qb->createNamedParameter($itemId, IQueryBuilder::PARAM_INT)))
			->orderBy('from_ts', 'ASC')
			->setMaxResults($limit)->setFirstResult($offset);
		/** @var list<Reservation> */
		return $this->findEntities($qb);
	}

	/** Stale reservations: still 'reserved' with to_ts past. @return list<Reservation> */
	public function expiredOpen(int $now, int $limit = 500): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('status', $qb->createNamedParameter('reserved')))
			->andWhere($qb->expr()->lt('to_ts', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
			->setMaxResults($limit);
		/** @var list<Reservation> */
		return $this->findEntities($qb);
	}

	/** CAS terminal transition from 'reserved'. Returns affected rows. */
	public function tryTransition(int $id, string $toStatus, ?int $convertedLoanId = null): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('status', $qb->createNamedParameter($toStatus))
			->set('converted_loan_id', $qb->createNamedParameter($convertedLoanId, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('reserved')));
		return $qb->executeStatement();
	}
}
