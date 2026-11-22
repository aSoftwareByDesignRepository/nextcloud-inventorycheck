<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Db\JournalEntry;
use OCA\InventoryCheck\Db\JournalEntryMapper;
use OCA\InventoryCheck\Db\MovementMapper;
use OCA\InventoryCheck\Exception\ConflictException;
use OCA\InventoryCheck\Support\LockGuard;
use OCP\DB\Exception as DbException;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * IV-COMPLIANCE-PACK: hash-chained append-only journal over movements.
 *
 * entry_hash = sha256(prev_hash_raw ‖ canonical(payload)).
 * Canonical serialization is an explicit field whitelist pinned by golden
 * vectors — never a reserialization of whatever row landed in the table.
 * Integers are big-endian 64-bit; strings length-prefixed (u32be ‖ UTF-8);
 * timestamps ISO-8601 UTC 'Y-m-d\TH:i:s\Z'. Ported from TourCheck's
 * ChainService (family reuse: same wire format, same lock discipline).
 *
 * Genesis: seq 1 carries prev_hash = NULL and hashes over an empty
 * prev-raw — pinned by the golden vector, not configurable.
 *
 * Concurrency: named lock + UNIQUE(site_id, seq) backstop. Appends run
 * inside the caller's movement transaction (NN-03) — a journal failure
 * rolls the movement back with it.
 *
 * Honest scope (NN-02): this detects tampering; it does not prevent a
 * full-table rewrite. No anchoring, no "audit-proof" claims.
 */
class JournalService
{
	public const SITE_ID = 'iv';
	private const LOCK_KEY = 'inventorycheck/journal/iv';

	public function __construct(
		private readonly JournalEntryMapper $journal,
		private readonly MovementMapper $movements,
		private readonly ILockingProvider $locking,
		private readonly Clock $clock,
		private readonly LoggerInterface $logger,
	) {
	}

	public static function i64be(int $v): string
	{
		return pack('N2', ($v >> 32) & 0xffffffff, $v & 0xffffffff);
	}

	public static function lp(string $s): string
	{
		return pack('N', strlen($s)) . $s;
	}

	/**
	 * Canonical bytes of a journal payload (fixed field order, whitelist only).
	 * @param array{seq:int,ts:int,actor:string,kind:string,itemId:int,fromLoc:int,toLoc:int,qty:int,lotCode:string,refType:string,refId:int,movementId:int} $p
	 */
	public static function canonicalPayload(array $p): string
	{
		return self::i64be($p['seq'])
			. self::i64be($p['ts'])
			. self::lp($p['actor'])
			. self::lp($p['kind'])
			. self::i64be($p['itemId'])
			. self::i64be($p['fromLoc'])
			. self::i64be($p['toLoc'])
			. self::i64be($p['qty'])
			. self::lp($p['lotCode'])
			. self::lp($p['refType'])
			. self::i64be($p['refId'])
			. self::i64be($p['movementId']);
	}

	public static function entryHashFor(?string $prevHashHex, string $canonical): string
	{
		$raw = $prevHashHex === null ? '' : (hex2bin($prevHashHex) ?: '');
		return hash('sha256', $raw . $canonical);
	}

	/**
	 * Append one entry for a committed movement. MUST be called inside the
	 * same DB transaction that wrote the movement (NN-03) — a failure here
	 * rolls both back.
	 *
	 * @param array{kind:string,actor:string,itemId:int,fromLoc:?int,toLoc:?int,qty:int,lotCode:?string,refType:?string,refId:?int,movementId:int} $m
	 */
	public function append(array $m): JournalEntry
	{
		try {
			return LockGuard::run($this->locking, self::LOCK_KEY, fn () => $this->appendLocked($m));
		} catch (LockedException) {
			throw new ConflictException('journal_busy', 'Journal is busy. Retry.');
		}
	}

	/** @param array{kind:string,actor:string,itemId:int,fromLoc:?int,toLoc:?int,qty:int,lotCode:?string,refType:?string,refId:?int,movementId:int} $m */
	private function appendLocked(array $m): JournalEntry
	{
		// Bounded retry: if a writer bypassed the named lock and took our
		// seq, the UNIQUE(site_id, seq) backstop throws — re-read the head,
		// re-chain once, then fail the whole movement txn (NN-03).
		for ($attempt = 1; $attempt <= 2; $attempt++) {
			$entry = $this->buildEntry($m);
			try {
				return $this->journal->insert($entry);
			} catch (DbException $e) {
				if (!self::isUniqueViolation($e)) {
					throw $e;
				}
				if ($attempt === 2) {
					$this->logger->warning('inventorycheck journal seq collision', ['seq' => $entry->getSeq()]);
					throw new ConflictException('journal_busy', 'Journal is busy. Retry.');
				}
			}
		}
		throw new \LogicException('unreachable');
	}

	/** Unique-violation detection — same convention as ScanIdempotencyService. */
	private static function isUniqueViolation(DbException $e): bool
	{
		if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
			return true;
		}
		$code = (string)$e->getCode();
		$msg = strtolower($e->getMessage());
		return $code === '23000'
			|| str_contains($msg, 'unique')
			|| str_contains($msg, 'duplicate');
	}

	/** @param array{kind:string,actor:string,itemId:int,fromLoc:?int,toLoc:?int,qty:int,lotCode:?string,refType:?string,refId:?int,movementId:int} $m */
	private function buildEntry(array $m): JournalEntry
	{
		$head = $this->journal->findHead(self::SITE_ID);
		$seq = $head === null ? 1 : (int)$head->getSeq() + 1;
		$prev = $head === null ? null : (string)$head->getEntryHash();
		$ts = $this->clock->now();

		$payload = [
			'seq' => $seq,
			'ts' => $ts,
			'actor' => (string)$m['actor'],
			'kind' => (string)$m['kind'],
			'itemId' => (int)$m['itemId'],
			'fromLoc' => (int)($m['fromLoc'] ?? 0),
			'toLoc' => (int)($m['toLoc'] ?? 0),
			'qty' => (int)$m['qty'],
			'lotCode' => (string)($m['lotCode'] ?? ''),
			'refType' => (string)($m['refType'] ?? ''),
			'refId' => (int)($m['refId'] ?? 0),
			'movementId' => (int)$m['movementId'],
		];

		$entry = new JournalEntry();
		$entry->setSiteId(self::SITE_ID);
		$entry->setSeq($seq);
		$entry->setTs($ts);
		$entry->setActor($payload['actor']);
		$entry->setKind($payload['kind']);
		$entry->setMovementId($payload['movementId']);
		$entry->setPayload(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		$entry->setPrevHash($prev);
		$entry->setEntryHash(self::entryHashFor($prev, self::canonicalPayload($payload)));
		return $entry;
	}

	public function entryCount(): int
	{
		return $this->journal->countForSite(self::SITE_ID);
	}

	/**
	 * Walk the chain: seq contiguity, prev linkage, hash recomputation.
	 * O(N) sequential, keyset-paged so memory stays flat on large chains.
	 * 'checked' is the number of entries actually read — on a failure it
	 * stops at the first detectable break, it never claims full coverage.
	 * @return array{intact:bool,checked:int,firstBadSeq:?int,reason:?string,head:?string}
	 */
	public function verify(int $pageSize = 1000): array
	{
		$prev = null;
		$expectSeq = 1;
		$checked = 0;
		$afterSeq = 0;
		$head = null;
		while (true) {
			$page = $this->journal->pageForSite(self::SITE_ID, $afterSeq, $pageSize);
			if ($page === []) {
				break;
			}
			foreach ($page as $row) {
				$checked++;
				$seq = (int)$row->getSeq();
				if ($seq !== $expectSeq) {
					return ['intact' => false, 'checked' => $checked, 'firstBadSeq' => $seq,
						'reason' => 'seq_gap', 'head' => $row->getEntryHash()];
				}
				if (!hash_equals((string)($prev ?? ''), (string)($row->getPrevHash() ?? ''))) {
					return ['intact' => false, 'checked' => $checked, 'firstBadSeq' => $seq,
						'reason' => 'prev_mismatch', 'head' => $row->getEntryHash()];
				}
				$stored = json_decode((string)$row->getPayload(), true);
				$fields = is_array($stored) ? $stored : [];
				$expect = self::entryHashFor($prev, self::canonicalPayload([
					'seq' => (int)($fields['seq'] ?? -1),
					'ts' => (int)($fields['ts'] ?? -1),
					'actor' => (string)($fields['actor'] ?? ''),
					'kind' => (string)($fields['kind'] ?? ''),
					'itemId' => (int)($fields['itemId'] ?? 0),
					'fromLoc' => (int)($fields['fromLoc'] ?? 0),
					'toLoc' => (int)($fields['toLoc'] ?? 0),
					'qty' => (int)($fields['qty'] ?? 0),
					'lotCode' => (string)($fields['lotCode'] ?? ''),
					'refType' => (string)($fields['refType'] ?? ''),
					'refId' => (int)($fields['refId'] ?? 0),
					'movementId' => (int)($fields['movementId'] ?? -1),
				]));
				if (!hash_equals($expect, (string)$row->getEntryHash())) {
					return ['intact' => false, 'checked' => $checked, 'firstBadSeq' => $seq,
						'reason' => 'hash_mismatch', 'head' => $row->getEntryHash()];
				}
				$prev = (string)$row->getEntryHash();
				$expectSeq++;
				$afterSeq = $seq;
				$head = $row;
			}
		}
		if ($head !== null) {
			// AC-10 tail-truncation: a committed movement with an id beyond
			// the chain head can only exist if tail rows were deleted.
			// Pre-journal legacy movements always sit below the head, so
			// they are never flagged.
			$maxMovementId = $this->movements->maxId();
			if ($maxMovementId > (int)$head->getMovementId()) {
				return ['intact' => false, 'checked' => $checked,
					'firstBadSeq' => null, 'reason' => 'tail_missing',
					'head' => (string)$head->getEntryHash()];
			}
		}
		return ['intact' => true, 'checked' => $checked,
			'firstBadSeq' => null, 'reason' => null,
			'head' => $head === null ? null : (string)$head->getEntryHash()];
	}
}
