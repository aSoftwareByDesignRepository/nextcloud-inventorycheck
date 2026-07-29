<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCA\InventoryCheck\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<CycleLine>
 */
class CycleLineMapper extends QBMapper
{
	use RowLocking;

	public const TABLE = 'iv_cc_line';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, CycleLine::class);
	}

	public function findById(int $id): CycleLine
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			throw new NotFoundException('unknown_count_line');
		}
	}

	public function lockById(int $id, bool $exclusive): CycleLine
	{
		$row = $this->selectRowByIdLocked($id, $exclusive);
		if ($row === null) {
			throw new NotFoundException('unknown_count_line');
		}
		/** @var CycleLine */
		return $this->mapRowToEntity($row);
	}

	/** @return list<CycleLine> */
	public function forCampaign(int $campaignId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('campaign_id', $qb->createNamedParameter($campaignId, IQueryBuilder::PARAM_INT)))
			->orderBy('item_id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Open or counting inventur lines for an item (B1: block delete/deactivate).
	 */
	public function countOpenCampaignsForItem(int $itemId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('l.id', 'cnt'))
			->from($this->getTableName(), 'l')
			->innerJoin('l', CycleCampaignMapper::TABLE, 'c', $qb->expr()->eq('l.campaign_id', 'c.id'))
			->where($qb->expr()->eq('l.item_id', $qb->createNamedParameter($itemId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->neq('c.status', $qb->createNamedParameter('closed')));
		$res = $qb->executeQuery();
		$cnt = (int)($res->fetchOne() ?: 0);
		$res->closeCursor();
		return $cnt;
	}
}
