<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Db\JournalEntry;
use OCA\InventoryCheck\Db\JournalEntryMapper;
use OCA\InventoryCheck\Db\MovementMapper;
use OCA\InventoryCheck\Service\Clock;
use OCA\InventoryCheck\Service\JournalService;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * IV-COMPLIANCE-PACK: golden vectors pin the canonical serialization
 * (AC-12 — byte-stable across PHP versions); verify walks seq contiguity,
 * prev linkage, hash recomputation, and the tail-truncation probe (AC-4/5/10).
 */
final class JournalServiceTest extends TestCase
{
	/** @var list<JournalEntry> in-memory chain — append() inserts here */
	private array $store = [];
	private int $nextId = 1;
	private int $maxMovementId = 0;
	private int $clock = 1_763_850_000;

	private function storeMock(): JournalEntryMapper
	{
		$journal = $this->createMock(JournalEntryMapper::class);
		$journal->method('findHead')->willReturnCallback(function () {
			return $this->store === [] ? null : end($this->store);
		});
		$journal->method('pageForSite')->willReturnCallback(function (string $site, int $afterSeq, int $limit) {
			return array_slice(array_values(array_filter(
				$this->store, fn (JournalEntry $e) => $e->getSeq() > $afterSeq)), 0, $limit);
		});
		$journal->method('countForSite')->willReturnCallback(function () {
			return count($this->store);
		});
		$journal->method('insert')->willReturnCallback(function (JournalEntry $e): JournalEntry {
			$e->setId($this->nextId++);
			$this->store[] = $e;
			return $e;
		});
		return $journal;
	}

	private function service(?JournalEntryMapper $journal = null): JournalService
	{
		$this->store = [];
		$this->nextId = 1;
		$this->maxMovementId = 0;
		$journal ??= $this->storeMock();
		$movements = $this->createMock(MovementMapper::class);
		$movements->method('maxId')->willReturnCallback(fn () => $this->maxMovementId);
		$locking = $this->createMock(ILockingProvider::class);
		$locking->method('acquireLock')->willReturnCallback(static function (): void {});
		$locking->method('releaseLock')->willReturnCallback(static function (): void {});
		$clock = $this->createMock(Clock::class);
		$clock->method('now')->willReturnCallback(fn () => $this->clock);
		return new JournalService($journal, $movements, $locking, $clock, new NullLogger());
	}

	/**
	 * Golden vector — computed once against the pinned serialization.
	 * If this test breaks, the chain format changed and every deployed
	 * chain becomes unverifiable: treat as a hard wire-format regression.
	 */
	public function testGoldenVectorCanonicalBytes(): void
	{
		$p = ['seq' => 1, 'ts' => 1763850000, 'actor' => 'alice', 'kind' => 'receive',
			'itemId' => 7, 'fromLoc' => 0, 'toLoc' => 3, 'qty' => 5, 'lotCode' => 'LOT-1',
			'refType' => '', 'refId' => 0, 'movementId' => 42];
		$this->assertSame(
			'0000000000000001000000006922371000000005616c69636500000007726563656976650000000000000007000000000000000000000000000000030000000000000005000000054c4f542d31000000000000000000000000000000000000002a',
			bin2hex(JournalService::canonicalPayload($p)),
		);
	}

	public function testGoldenVectorGenesisAndLinkHashes(): void
	{
		$p1 = ['seq' => 1, 'ts' => 1763850000, 'actor' => 'alice', 'kind' => 'receive',
			'itemId' => 7, 'fromLoc' => 0, 'toLoc' => 3, 'qty' => 5, 'lotCode' => 'LOT-1',
			'refType' => '', 'refId' => 0, 'movementId' => 42];
		$h1 = JournalService::entryHashFor(null, JournalService::canonicalPayload($p1));
		$this->assertSame('5c68be3c03763d558ac77c87244e93a8591998e27f2d72fe76aa5cced60170e7', $h1);

		$p2 = ['seq' => 2, 'ts' => 1763850060, 'actor' => 'bob', 'kind' => 'issue',
			'itemId' => 7, 'fromLoc' => 3, 'toLoc' => 0, 'qty' => -2, 'lotCode' => '',
			'refType' => 'adjust', 'refId' => 11, 'movementId' => 43];
		$this->assertSame(
			'5e42917f32b5010f826494f5dc3a0bd3527aa8fb2444c8ec385685370e7096c2',
			JournalService::entryHashFor($h1, JournalService::canonicalPayload($p2)),
		);
	}

	private function append(JournalService $svc, array $over = []): JournalEntry
	{
		$m = array_merge([
			'kind' => 'receive', 'actor' => 'alice', 'itemId' => 7,
			'fromLoc' => null, 'toLoc' => 3, 'qty' => 5, 'lotCode' => null,
			'refType' => null, 'refId' => null, 'movementId' => 100 + count($this->store),
		], $over);
		$this->maxMovementId = max($this->maxMovementId, (int)$m['movementId']);
		return $svc->append($m);
	}

	public function testAppendChainsSeqPrevHashAndPayload(): void
	{
		$svc = $this->service();
		$e1 = $this->append($svc);
		$this->assertSame(1, $e1->getSeq());
		$this->assertNull($e1->getPrevHash());
		$this->assertSame(64, strlen($e1->getEntryHash()));
		$payload = json_decode($e1->getPayload(), true);
		$this->assertSame(['seq', 'ts', 'actor', 'kind', 'itemId', 'fromLoc', 'toLoc', 'qty', 'lotCode', 'refType', 'refId', 'movementId'], array_keys($payload));

		$e2 = $this->append($svc, ['kind' => 'issue', 'qty' => -2]);
		$this->assertSame(2, $e2->getSeq());
		$this->assertSame($e1->getEntryHash(), $e2->getPrevHash());
		$this->assertNotSame($e1->getEntryHash(), $e2->getEntryHash());
	}

	public function testVerifyIntactChain(): void
	{
		$svc = $this->service();
		$this->append($svc);
		$this->append($svc, ['kind' => 'transfer_out']);
		$this->append($svc, ['kind' => 'transfer_in']);
		$r = $svc->verify();
		$this->assertTrue($r['intact']);
		$this->assertSame(3, $r['checked']);
		$this->assertNull($r['firstBadSeq']);
	}

	public function testVerifyEmptyJournalIsIntact(): void
	{
		$r = $this->service()->verify();
		$this->assertTrue($r['intact']);
		$this->assertSame(0, $r['checked']);
	}

	public function testVerifyDetectsEditedPayload(): void
	{
		$svc = $this->service();
		$this->append($svc);
		$e2 = $this->append($svc);
		$this->append($svc);
		// tamper: change qty inside entry 2's stored payload
		$p = json_decode($e2->getPayload(), true);
		$p['qty'] = 999;
		$e2->setPayload(json_encode($p));
		$r = $svc->verify();
		$this->assertFalse($r['intact']);
		$this->assertSame(2, $r['firstBadSeq']);
		$this->assertSame('hash_mismatch', $r['reason']);
	}

	public function testVerifyDetectsDeletedMiddleEntry(): void
	{
		$svc = $this->service();
		$this->append($svc);
		$this->append($svc);
		$this->append($svc);
		array_splice($this->store, 1, 1); // delete seq 2
		$r = $svc->verify();
		$this->assertFalse($r['intact']);
		// AC-10: firstBadSeq points at the next entry after the hole.
		$this->assertSame(3, $r['firstBadSeq']);
		$this->assertSame('seq_gap', $r['reason']);
	}

	public function testVerifyDetectsRehashedPrevLink(): void
	{
		$svc = $this->service();
		$this->append($svc);
		$e2 = $this->append($svc);
		// attacker rewrites entry 2's prev pointer without rehashing payload
		$e2->setPrevHash(str_repeat('0', 64));
		$r = $svc->verify();
		$this->assertFalse($r['intact']);
		$this->assertSame(2, $r['firstBadSeq']);
		$this->assertSame('prev_mismatch', $r['reason']);
	}

	public function testVerifyDetectsTailTruncationViaMovementAhead(): void
	{
		$svc = $this->service();
		$this->append($svc); // journal head movementId = 100
		$this->maxMovementId = 150; // a committed movement outlives the chain tail
		$r = $svc->verify();
		$this->assertFalse($r['intact']);
		$this->assertSame('tail_missing', $r['reason']);
	}

	public function testVerifyIgnoresLegacyMovementsBelowHead(): void
	{
		$svc = $this->service();
		$this->maxMovementId = 50; // pre-journal movement ids sit below the head
		$this->append($svc); // movementId = 100
		$r = $svc->verify();
		$this->assertTrue($r['intact']);
	}

	public function testVerifyWalksAcrossPageBoundaries(): void
	{
		$svc = $this->service();
		for ($i = 0; $i < 5; $i++) {
			$this->append($svc);
		}
		$r = $svc->verify(2); // 3 pages: 2+2+1
		$this->assertTrue($r['intact']);
		$this->assertSame(5, $r['checked']);
	}

	public function testVerifyDetectsGapSpanningAPageBreak(): void
	{
		$svc = $this->service();
		$this->append($svc);
		$this->append($svc);
		$this->append($svc);
		array_splice($this->store, 1, 1); // seq 2 sits on page 1, hole surfaces on page 2
		$r = $svc->verify(1); // every entry its own page
		$this->assertFalse($r['intact']);
		$this->assertSame(3, $r['firstBadSeq']);
		$this->assertSame('seq_gap', $r['reason']);
	}

	public function testAppendRetriesOnceOnUniqueCollision(): void
	{
		$this->store = [];
		$this->nextId = 1;
		// Simulate a lock-bypass writer: first insert "collides", a foreign
		// row lands under us, the retry re-reads the head and chains on it.
		$bypass = new JournalEntry();
		$bypass->setSeq(2);
		$bypass->setTs(1);
		$bypass->setActor('ghost');
		$bypass->setKind('receive');
		$bypass->setMovementId(999);
		$bypass->setPayload('{}');
		$bypass->setEntryHash(str_repeat('a', 64));
		$thrown = 0;
		$journal = $this->createMock(JournalEntryMapper::class);
		$journal->method('findHead')->willReturnCallback(function () {
			return $this->store === [] ? null : end($this->store);
		});
		// Emulates the UNIQUE(site_id, seq) backstop plus the race: a foreign
		// writer slips a seq-2 row in between our head-read and our insert.
		$journal->method('insert')->willReturnCallback(
			function (JournalEntry $e) use (&$thrown, $bypass): JournalEntry {
				if ($e->getSeq() === 2 && !in_array($bypass, $this->store, true)) {
					$this->store[] = $bypass; // ghost row lands in the race window
				}
				foreach ($this->store as $row) {
					if ($row->getSeq() === $e->getSeq()) {
						$thrown++;
						throw new \OCP\DB\Exception('Duplicate entry for key iv_jrn_seq_uq', 23000);
					}
				}
				$e->setId($this->nextId++);
				$this->store[] = $e;
				return $e;
			});
		$svc = $this->service($journal);
		$this->append($svc); // seq 1 occupies the head
		$this->maxMovementId = 0; // bypass row is ghost state, not a real movement

		$e = $this->append($svc, ['kind' => 'issue', 'qty' => -1]);
		$this->assertSame(1, $thrown, 'first attempt must hit the unique backstop');
		$this->assertSame(3, $e->getSeq(), 'retry must re-read the head and take seq+1');
		$this->assertSame(str_repeat('a', 64), $e->getPrevHash(), 'retry chains onto the bypass row');
	}

	public function testAppendFailsAfterSecondCollision(): void
	{
		$this->store = [];
		$this->nextId = 1;
		$journal = $this->createMock(JournalEntryMapper::class);
		$journal->method('findHead')->willReturnCallback(function () {
			return $this->store === [] ? null : end($this->store);
		});
		$journal->method('insert')->willReturnCallback(function () {
			throw new \OCP\DB\Exception('Duplicate entry', 23000);
		});
		$svc = $this->service($journal);
		try {
			$this->append($svc);
			$this->fail('expected journal_busy');
		} catch (\OCA\InventoryCheck\Exception\ConflictException $e) {
			$this->assertSame('journal_busy', $e->getErrorCode());
		}
	}

	public function testAppendRethrowsNonUniqueDbError(): void
	{
		$this->store = [];
		$journal = $this->createMock(JournalEntryMapper::class);
		$journal->method('findHead')->willReturnCallback(function () {
			return $this->store === [] ? null : end($this->store);
		});
		$journal->method('insert')->willReturnCallback(function () {
			throw new \OCP\DB\Exception('Lost connection', 2013);
		});
		$this->expectException(\OCP\DB\Exception::class);
		$this->append($this->service($journal));
	}
}
