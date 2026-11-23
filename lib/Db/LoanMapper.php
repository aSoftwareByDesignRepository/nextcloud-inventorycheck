<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<Loan> */
class LoanMapper extends QBMapper
{
	public const TABLE = 'iv_loans';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, Loan::class);
	}

	public function findById(int $id): Loan
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		return $this->findEntity($qb);
	}

	/** Row lock inside an open transaction — CAS anchor for concurrent checkins. */
	public function findByIdForUpdate(int $id): Loan
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->forUpdate();
		return $this->findEntity($qb);
	}

	/**
	 * CAS return counter: succeeds only while the loan is open and the
	 * outstanding qty can cover $qty. 1 = applied, 0 = lost the race /
	 * over-return — caller maps 0 to a conflict/validation error.
	 */
	public function tryApplyReturn(int $loanId, int $qty, int $now, string $actorUid): int
	{
		$qb = $this->db->getQueryBuilder();
		// Order matters: MySQL evaluates SET left-to-right against updated
		// values, Postgres always against the old row. status/returned_at
		// CASEs must run BEFORE qty_returned so both engines see the
		// pre-update counter (old + qty >= qty).
		$qb->update($this->getTableName())
			->set('status', $qb->createFunction(
				"CASE WHEN qty_returned + " . $qb->createNamedParameter($qty, IQueryBuilder::PARAM_INT) . " >= qty THEN 'returned' ELSE 'partial' END"
			))
			->set('returned_at', $qb->createFunction(
				"CASE WHEN qty_returned + " . $qb->createNamedParameter($qty, IQueryBuilder::PARAM_INT) . " >= qty THEN " . $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT) . " ELSE NULL END"
			))
			->set('checked_in_by', $qb->createNamedParameter($actorUid))
			->set('qty_returned', $qb->createFunction('qty_returned + ' . $qb->createNamedParameter($qty, IQueryBuilder::PARAM_INT)))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($loanId)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(['open', 'partial'], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->lte(
				$qb->createFunction('qty_returned + ' . $qb->createNamedParameter($qty, IQueryBuilder::PARAM_INT)),
				$qb->createFunction('qty')
			));
		return $qb->executeStatement();
	}

	/** @return list<Loan> open|partial loans for a person */
	public function openForPerson(int $personId, int $limit = 50, int $offset = 0): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('person_id', $qb->createNamedParameter($personId)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(['open', 'partial'], IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('due_at', 'ASC')
			->setMaxResults($limit)->setFirstResult($offset);
		return $this->findEntities($qb);
	}

	/** @return list<Loan> history rows for a person (terminal states) */
	public function historyForPerson(int $personId, int $limit = 50, int $offset = 0): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('person_id', $qb->createNamedParameter($personId)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(['returned', 'lost'], IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('checked_out_at', 'DESC')
			->setMaxResults($limit)->setFirstResult($offset);
		return $this->findEntities($qb);
	}

	/** @return list<Loan> open|partial loans against an item */
	public function openForItem(int $itemId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('item_id', $qb->createNamedParameter($itemId)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(['open', 'partial'], IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('due_at', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<Loan> open/partial loans at visible locations, optionally overdue-only */
	public function openScoped(?array $locationIds, ?int $now = null, bool $overdueOnly = false, int $limit = 200, int $offset = 0): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->in('status', $qb->createNamedParameter(['open', 'partial'], IQueryBuilder::PARAM_STR_ARRAY)));
		if ($locationIds !== null) {
			if ($locationIds === []) {
				return [];
			}
			$qb->andWhere($qb->expr()->in('location_id', $qb->createNamedParameter($locationIds, IQueryBuilder::PARAM_INT_ARRAY)));
		}
		if ($overdueOnly && $now !== null) {
			$qb->andWhere($qb->expr()->isNotNull('due_at'))
				->andWhere($qb->expr()->lt('due_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));
		}
		$qb->orderBy('due_at', 'ASC')
			->setMaxResults($limit)->setFirstResult($offset);
		return $this->findEntities($qb);
	}

	/** @return list<Loan> loans due within [from,to) that are still open — due-scan job source */
	public function dueBetween(int $from, int $to, int $limit = 500): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->in('status', $qb->createNamedParameter(['open', 'partial'], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->isNotNull('due_at'))
			->andWhere($qb->expr()->lt('due_at', $qb->createNamedParameter($to, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('due_at', $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)))
			->orderBy('due_at', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	public function countOpenForPerson(int $personId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'n'))->from($this->getTableName())
			->where($qb->expr()->eq('person_id', $qb->createNamedParameter($personId)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(['open', 'partial'], IQueryBuilder::PARAM_STR_ARRAY)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/** @return array{open:int,overdue:int,dueToday:int} overview card counts for a location scope */
	public function overviewCounts(?array $locationIds, int $now): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'n'))->from($this->getTableName())
			->where($qb->expr()->in('status', $qb->createNamedParameter(['open', 'partial'], IQueryBuilder::PARAM_STR_ARRAY)));
		if ($locationIds !== null) {
			if ($locationIds === []) {
				return ['open' => 0, 'overdue' => 0, 'dueToday' => 0];
			}
			$qb->andWhere($qb->expr()->in('location_id', $qb->createNamedParameter($locationIds, IQueryBuilder::PARAM_INT_ARRAY)));
		}
		$open = (int)$qb->executeQuery()->fetchOne();

		$dayStart = $now - ($now % 86400);
		$qb2 = $this->db->getQueryBuilder();
		$qb2->select($qb2->func()->count('id', 'n'))->from($this->getTableName())
			->where($qb2->expr()->in('status', $qb2->createNamedParameter(['open', 'partial'], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb2->expr()->isNotNull('due_at'))
			->andWhere($qb2->expr()->lt('due_at', $qb2->createNamedParameter($now, IQueryBuilder::PARAM_INT)));
		if ($locationIds !== null) {
			$qb2->andWhere($qb2->expr()->in('location_id', $qb2->createNamedParameter($locationIds, IQueryBuilder::PARAM_INT_ARRAY)));
		}
		$overdue = (int)$qb2->executeQuery()->fetchOne();

		$qb3 = $this->db->getQueryBuilder();
		$qb3->select($qb3->func()->count('id', 'n'))->from($this->getTableName())
			->where($qb3->expr()->in('status', $qb3->createNamedParameter(['open', 'partial'], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb3->expr()->isNotNull('due_at'))
			->andWhere($qb3->expr()->gte('due_at', $qb3->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
			->andWhere($qb3->expr()->lt('due_at', $qb3->createNamedParameter($dayStart + 86400, IQueryBuilder::PARAM_INT)));
		if ($locationIds !== null) {
			$qb3->andWhere($qb3->expr()->in('location_id', $qb3->createNamedParameter($locationIds, IQueryBuilder::PARAM_INT_ARRAY)));
		}
		$dueToday = (int)$qb3->executeQuery()->fetchOne();

		return ['open' => $open, 'overdue' => $overdue, 'dueToday' => $dueToday];
	}
}
