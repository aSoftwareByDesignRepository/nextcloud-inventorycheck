<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Db\JournalEntry;
use OCA\InventoryCheck\Db\JournalEntryMapper;
use OCA\InventoryCheck\Db\MovementMapper;
use OCA\InventoryCheck\Exception\ConflictException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\JournalService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\MovementService;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Server;
use Test\TestCase;

/**
 * IV-COMPLIANCE-PACK — real-DB: movement commit chains a journal entry in
 * the same txn (AC-1); verify flags edited payloads, holes and missing
 * tails (AC-4/5/10); a journal failure rolls the movement back (AC-11).
 *
 * @group DB
 */
final class JournalIntegrationTest extends TestCase
{
	private string $uid = 'admin';
	private MovementService $movements;
	private LocationService $locations;
	private ItemService $items;
	private JournalService $journal;
	private JournalEntryMapper $journalMap;
	private MovementMapper $movementMap;
	private IDBConnection $db;

	protected function setUp(): void
	{
		parent::setUp();
		$c = (new Application())->getContainer();
		$this->movements = $c->get(MovementService::class);
		$this->locations = $c->get(LocationService::class);
		$this->items = $c->get(ItemService::class);
		$this->journal = $c->get(JournalService::class);
		$this->journalMap = $c->get(JournalEntryMapper::class);
		$this->movementMap = $c->get(MovementMapper::class);
		$this->db = $c->get(IDBConnection::class);
		$config = Server::get(\OCP\IConfig::class);
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ALLOW_NEGATIVE, '0');
	}

	private function fixture(): array
	{
		$suffix = bin2hex(random_bytes(4));
		$loc = $this->locations->create($this->uid, [
			'code' => 'J-LOC-' . $suffix,
			'name' => 'Journal loc',
			'kind' => 'warehouse',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => 'J-SKU-' . $suffix,
			'name' => 'Journal item',
		]);
		return [(int)$item['id'], (int)$loc['id']];
	}

	private function entryForMovement(int $movementId): ?JournalEntry
	{
		foreach ($this->journalMap->allForSite(JournalService::SITE_ID) as $e) {
			if ((int)$e->getMovementId() === $movementId) {
				return $e;
			}
		}
		return null;
	}

	public function testMovementCommitChainsExactlyOneEntry(): void
	{
		[$itemId, $locId] = $this->fixture();
		$before = $this->journal->entryCount();
		$res = $this->movements->receive($this->uid, $itemId, $locId, 4, 'chain me');
		$movementId = (int)$res['movements'][0]['id'];
		$this->assertSame($before + 1, $this->journal->entryCount());

		$entry = $this->entryForMovement($movementId);
		$this->assertNotNull($entry, 'movement must produce a journal row');
		$this->assertSame('receive', $entry->getKind());
		$this->assertSame($this->uid, $entry->getActor());
		$payload = json_decode($entry->getPayload(), true);
		$this->assertSame($itemId, $payload['itemId']);
		$this->assertSame($locId, $payload['toLoc']);
		$this->assertSame(4, $payload['qty']);
		$this->assertSame(64, strlen($entry->getEntryHash()));
	}

	public function testTransferChainsBothLegsInOrder(): void
	{
		[$itemId, $locA] = $this->fixture();
		$locB = $this->locations->create($this->uid, [
			'code' => 'J-LOC2-' . bin2hex(random_bytes(4)),
			'name' => 'Journal loc B',
			'kind' => 'van',
		]);
		$this->movements->receive($this->uid, $itemId, $locA, 3, 'seed');
		$res = $this->movements->transfer($this->uid, $itemId, $locA, (int)$locB['id'], 2, 'move');
		$outEntry = $this->entryForMovement((int)$res['movements'][0]['id']);
		$inEntry = $this->entryForMovement((int)$res['movements'][1]['id']);
		$this->assertNotNull($outEntry);
		$this->assertNotNull($inEntry);
		$this->assertSame('transfer_out', $outEntry->getKind());
		$this->assertSame('transfer_in', $inEntry->getKind());
		$this->assertSame($inEntry->getSeq(), $outEntry->getSeq() + 1);
		$this->assertSame($outEntry->getEntryHash(), $inEntry->getPrevHash());
		$in = json_decode($inEntry->getPayload(), true);
		$this->assertSame($locA, $in['fromLoc']);
		$this->assertSame((int)$locB['id'], $in['toLoc']);
	}

	public function testVerifyIntactThenFlagsTamper(): void
	{
		[$itemId, $locId] = $this->fixture();
		$this->movements->receive($this->uid, $itemId, $locId, 1, 'a');
		$this->movements->receive($this->uid, $itemId, $locId, 1, 'b');
		$this->assertTrue($this->journal->verify()['intact']);

		// Hand-edit the newest entry's payload — verify must flag exactly it.
		$head = $this->journalMap->findHead(JournalService::SITE_ID);
		$this->assertNotNull($head);
		$qb = $this->db->getQueryBuilder();
		$qb->update('iv_journal')
			->set('payload', $qb->createNamedParameter('{"seq":-1}'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$head->getId())));
		$qb->executeStatement();
		$r = $this->journal->verify();
		$this->assertFalse($r['intact']);
		$this->assertSame((int)$head->getSeq(), $r['firstBadSeq']);
	}

	public function testVerifyFlagsUnjournaledTailMovement(): void
	{
		[$itemId, $locId] = $this->fixture();
		$this->movements->receive($this->uid, $itemId, $locId, 1, 'seed');
		// Insert a movement row bypassing the service — the chain tail is now short.
		$qb = $this->db->getQueryBuilder();
		$qb->insert('iv_movements')
			->values([
				'item_id' => $qb->createNamedParameter($itemId),
				'location_id' => $qb->createNamedParameter($locId),
				'kind' => $qb->createNamedParameter('receive'),
				'qty_delta' => $qb->createNamedParameter(1),
				'qty_after' => $qb->createNamedParameter(2),
				'created_at' => $qb->createNamedParameter(time()),
				'created_by' => $qb->createNamedParameter($this->uid),
			]);
		$qb->executeStatement();
		$r = $this->journal->verify();
		$this->assertFalse($r['intact']);
		$this->assertSame('tail_missing', $r['reason']);
	}

	public function testJournalFailureRollsMovementBack(): void
	{
		[$itemId, $locId] = $this->fixture();
		$locking = Server::get(ILockingProvider::class);
		$locking->acquireLock('inventorycheck/journal/iv', ILockingProvider::LOCK_EXCLUSIVE);
		$threw = false;
		try {
			// Holding the journal lock makes append fail after its bounded
			// retry — the movement must roll back with it (NN-03).
			$this->movements->receive($this->uid, $itemId, $locId, 5, 'must not commit');
		} catch (ConflictException $e) {
			$threw = $e->getErrorCode() === 'journal_busy';
		} finally {
			$locking->releaseLock('inventorycheck/journal/iv', ILockingProvider::LOCK_EXCLUSIVE);
		}
		$this->assertTrue($threw, 'journal-contended receive must fail with journal_busy');
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'n'))->from('iv_movements')
			->where($qb->expr()->eq('item_id', $qb->createNamedParameter($itemId)))
			->andWhere($qb->expr()->eq('location_id', $qb->createNamedParameter($locId)));
		$this->assertSame(0, (int)$qb->executeQuery()->fetchOne(), 'rolled-back movement must not persist');
	}
}
