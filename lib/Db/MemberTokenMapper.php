<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<MemberToken> */
class MemberTokenMapper extends QBMapper
{
	public const TABLE = 'iv_member_tokens';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, MemberToken::class);
	}

	public function findActiveByHash(string $hash): ?MemberToken
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('token_hash', $qb->createNamedParameter($hash)))
			->andWhere($qb->expr()->isNull('revoked_at'));
		try {
			/** @var MemberToken */
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function liveForPerson(int $personId): ?MemberToken
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('person_id', $qb->createNamedParameter($personId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('revoked_at'))
			->orderBy('created_at', 'DESC')
			->setMaxResults(1);
		try {
			/** @var MemberToken */
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function revokeForPerson(int $personId, int $now): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('revoked_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('person_id', $qb->createNamedParameter($personId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('revoked_at'));
		return $qb->executeStatement();
	}

	public function touchLastUsed(int $id, int $now): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('last_used_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
