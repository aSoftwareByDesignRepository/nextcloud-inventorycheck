<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCA\InventoryCheck\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<ScanDevice> */
class ScanDeviceMapper extends QBMapper
{
	public const TABLE = 'iv_scan_devices';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, ScanDevice::class);
	}

	public function findById(int $id): ScanDevice
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			throw new NotFoundException();
		}
	}

	/** @return list<ScanDevice> */
	public function findAll(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function countActive(): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'cnt'))->from($this->getTableName())
			->where($qb->expr()->eq('active', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)));
		$result = $qb->executeQuery();
		$total = (int)($result->fetchOne() ?: 0);
		$result->closeCursor();
		return $total;
	}

	/** @return list<ScanDevice> paired + active */
	public function findPairedActive(): array
	{
		$all = $this->findAll();
		return array_values(array_filter(
			$all,
			static fn (ScanDevice $d): bool => $d->getActive() && $d->getPairedAt() !== null && $d->getTokenHash(),
		));
	}

	/**
	 * Lookup by token hash including deactivated rows.
	 * Callers must enforce active/paired (SPEC §9.1 rung 1 vs 5).
	 */
	public function findByTokenHash(string $hash): ?ScanDevice
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('token_hash', $qb->createNamedParameter($hash)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Pending pairing row for an exact code hash (not yet claimed).
	 */
	public function findPendingByPairCodeHash(string $hash): ?ScanDevice
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('pair_code_hash', $qb->createNamedParameter($hash)))
			->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)))
			->andWhere($qb->expr()->isNull('token_hash'));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Atomic pair claim — only one concurrent caller wins (AC-18).
	 * Requires an unused pending code (token_hash still NULL) and clears it in the same UPDATE.
	 */
	public function claimPairing(int $id, string $expectedPairHash, string $tokenHash, int $now): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('token_hash', $qb->createNamedParameter($tokenHash))
			->set('paired_at', $qb->createNamedParameter($now, \PDO::PARAM_INT))
			->set('last_seen_at', $qb->createNamedParameter($now, \PDO::PARAM_INT))
			->set('pair_code_hash', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('pair_code_expires', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('pair_code_hash', $qb->createNamedParameter($expectedPairHash)))
			->andWhere($qb->expr()->gte('pair_code_expires', $qb->createNamedParameter($now, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)))
			->andWhere($qb->expr()->isNull('token_hash'));
		return $qb->executeStatement() === 1;
	}

	/**
	 * Atomic pairing-code rotation (§9.3.4) — clears any live token in one UPDATE.
	 */
	public function rotatePairCode(int $id, string $pairCodeHash, int $expiresAt): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('pair_code_hash', $qb->createNamedParameter($pairCodeHash))
			->set('pair_code_expires', $qb->createNamedParameter($expiresAt, \PDO::PARAM_INT))
			->set('token_hash', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('paired_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)));
		return $qb->executeStatement() === 1;
	}
}
