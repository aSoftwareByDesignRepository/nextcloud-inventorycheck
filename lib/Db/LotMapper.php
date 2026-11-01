<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Lot>
 */
class LotMapper extends QBMapper
{
	use RowLocking;

	public const TABLE = 'iv_lots';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, Lot::class);
	}

	public function findById(int $id): Lot
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			throw new NotFoundException('unknown_lot');
		}
	}

	public function findByItemAndCode(int $itemId, string $lotCode): ?Lot
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('item_id', $qb->createNamedParameter($itemId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('lot_code', $qb->createNamedParameter($lotCode)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * MF-04 upsert inside the receive transaction (must be called under the
	 * item-row lock): first-seen (item, lot) takes the supplied expiry;
	 * a re-receive carrying a *differing* expiry is rejected — never silently
	 * overwritten (recall-grade data). Null on re-receive keeps the record.
	 */
	public function upsertForReceive(int $itemId, string $lotCode, ?int $expiresAt): Lot
	{
		$lot = new Lot();
		$lot->setItemId($itemId);
		$lot->setLotCode($lotCode);
		$lot->setExpiresAt($expiresAt);
		try {
			return $this->insert($lot);
		} catch (\Throwable $e) {
			if (!UniqueViolation::is($e) && !self::looksUnique($e)) {
				throw $e;
			}
		}
		// Unique race or existing lot: lock the row, then apply the rule.
		$row = $this->selectLotLocked($itemId, $lotCode);
		if ($row === null) {
			// Insert was rolled back elsewhere — surface as conflict, not a null deref.
			throw new ValidationException('validation_failed', '', [
				['field' => 'lotCode', 'code' => 'validation_failed'],
			]);
		}
		$existing = $row['expires_at'] === null ? null : (int)$row['expires_at'];
		if ($expiresAt !== null && $existing !== null && $expiresAt !== $existing) {
			throw new ValidationException('expiry_conflict', '', [
				['field' => 'expiresAt', 'code' => 'expiry_conflict'],
			]);
		}
		if ($expiresAt !== null && $existing === null) {
			$qb = $this->db->getQueryBuilder();
			$qb->update($this->getTableName())
				->set('expires_at', $qb->createNamedParameter($expiresAt, \PDO::PARAM_INT))
				->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$row['id'], \PDO::PARAM_INT)));
			$qb->executeStatement();
		}
		return $this->findByItemAndCode($itemId, $lotCode);
	}

	/** @return array<string, mixed>|null */
	private function selectLotLocked(int $itemId, string $lotCode): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('item_id', $qb->createNamedParameter($itemId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('lot_code', $qb->createNamedParameter($lotCode)));
		$sql = $qb->getSQL() . $this->rowLockSuffix(true);
		$result = $this->db->executeQuery($sql, $qb->getParameters(), $qb->getParameterTypes());
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false ? null : $row;
	}

	/** Office+ correction path — the only overwrite (audit-logged by caller). */
	public function setExpiry(int $lotId, ?int $expiresAt): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('expires_at', $expiresAt === null
				? $qb->createNamedParameter(null, \PDO::PARAM_NULL)
				: $qb->createNamedParameter($expiresAt, \PDO::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($lotId, \PDO::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Lots expiring within $horizonSeconds from $now (expired included).
	 *
	 * @return list<Lot>
	 */
	public function expiringWithin(int $now, int $horizonSeconds, int $limit = 500): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->isNotNull('expires_at'))
			->andWhere($qb->expr()->lte('expires_at', $qb->createNamedParameter($now + $horizonSeconds, \PDO::PARAM_INT)))
			->orderBy('expires_at', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/**
	 * Recall lookup (F-23): every lot row whose code matches.
	 *
	 * @return list<Lot>
	 */
	public function findByCode(string $lotCode, int $limit = 200): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('lot_code', $qb->createNamedParameter($lotCode)))
			->orderBy('item_id', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/** @return list<Lot> */
	public function findByItem(int $itemId, int $limit = 200): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('item_id', $qb->createNamedParameter($itemId, \PDO::PARAM_INT)))
			->orderBy('expires_at', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	private static function looksUnique(\Throwable $e): bool
	{
		$msg = strtolower($e->getMessage());
		return str_contains($msg, 'unique') || str_contains($msg, 'duplicate')
			|| (string)$e->getCode() === '23000';
	}
}
