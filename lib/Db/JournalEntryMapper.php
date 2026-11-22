<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Insert-only by design (NN-01): no update()/delete()/deleteAll here —
 * a rewrite path on the journal would erase exactly the tamper evidence
 * the chain exists to preserve. Uninstall drops the table whole via
 * UninstallDropTables.
 *
 * @extends QBMapper<JournalEntry>
 */
class JournalEntryMapper extends QBMapper
{
	public const TABLE = 'iv_journal';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, JournalEntry::class);
	}

	/** Chain head for a site — the entry append links against. */
	public function findHead(string $siteId): ?JournalEntry
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)))
			->orderBy('seq', 'DESC')
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * One page of the verify walk, keyset-paginated by seq — keeps memory
	 * flat on large chains (no OFFSET scan, no full-table hydrate).
	 * @return list<JournalEntry>
	 */
	public function pageForSite(string $siteId, int $afterSeq, int $limit): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)))
			->andWhere($qb->expr()->gt('seq', $qb->createNamedParameter($afterSeq)))
			->orderBy('seq', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/**
	 * All entries for a site, seq ascending — for tests and small exports.
	 * The verify walk uses pageForSite() instead (bounded memory).
	 * @return list<JournalEntry>
	 */
	public function allForSite(string $siteId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)))
			->orderBy('seq', 'ASC');
		return $this->findEntities($qb);
	}

	public function countForSite(string $siteId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'n'))->from($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId)));
		return (int)$qb->executeQuery()->fetchOne();
	}
}
