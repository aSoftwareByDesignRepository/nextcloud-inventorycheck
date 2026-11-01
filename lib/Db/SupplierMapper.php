<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCA\InventoryCheck\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Supplier>
 */
class SupplierMapper extends QBMapper
{
	public const TABLE = 'iv_suppliers';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, Supplier::class);
	}

	public function findById(int $id): Supplier
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			throw new NotFoundException('unknown_supplier');
		}
	}

	/** @return list<Supplier> */
	public function listActive(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('active', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)))
			->orderBy('name', 'ASC');
		return $this->findEntities($qb);
	}
}
