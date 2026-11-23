<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<LoanRequest> */
class LoanRequestMapper extends QBMapper
{
	public const TABLE = 'iv_loan_requests';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, LoanRequest::class);
	}

	public function findById(int $id): LoanRequest
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		/** @var LoanRequest */
		return $this->findEntity($qb);
	}

	/** @return list<LoanRequest> */
	public function listByStatus(string $status, int $limit = 200, int $offset = 0): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('status', $qb->createNamedParameter($status)))
			->orderBy('created_at', 'ASC')
			->setMaxResults($limit)->setFirstResult($offset);
		/** @var list<LoanRequest> */
		return $this->findEntities($qb);
	}

	/** Open requests a person may still amend/cancel. @return list<LoanRequest> */
	public function openForPerson(int $personId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('person_id', $qb->createNamedParameter($personId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(['pending'], IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('created_at', 'ASC');
		/** @var list<LoanRequest> */
		return $this->findEntities($qb);
	}

	/** All requests for the portal history (any status). @return list<LoanRequest> */
	public function listForPerson(int $personId, int $limit = 50): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('person_id', $qb->createNamedParameter($personId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC')
			->setMaxResults($limit);
		/** @var list<LoanRequest> */
		return $this->findEntities($qb);
	}

	public function countOpenForPerson(int $personId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from(self::TABLE)
			->where($qb->expr()->eq('person_id', $qb->createNamedParameter($personId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('pending')));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/** CAS: decide only while still pending. Returns affected rows. */
	public function tryDecide(int $id, string $toStatus, string $decidedBy, int $decidedAt, ?int $loanId, ?string $note): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('status', $qb->createNamedParameter($toStatus))
			->set('decided_by', $qb->createNamedParameter($decidedBy))
			->set('decided_at', $qb->createNamedParameter($decidedAt, IQueryBuilder::PARAM_INT))
			->set('loan_id', $qb->createNamedParameter($loanId, IQueryBuilder::PARAM_INT))
			->set('note', $qb->createNamedParameter($note))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('pending')));
		return $qb->executeStatement();
	}
}
