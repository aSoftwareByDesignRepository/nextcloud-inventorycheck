<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Db\CycleCampaign;
use OCA\InventoryCheck\Db\CycleCampaignMapper;
use OCA\InventoryCheck\Db\CycleLine;
use OCA\InventoryCheck\Db\CycleLineMapper;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\LocationMapper;
use OCA\InventoryCheck\Exception\ConflictException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCP\IDBConnection;

/**
 * Cycle-count / Inventur campaigns (Wave B1).
 *
 * States: open → counting → closed.
 * Each counted line posts one adjust (mode=set) or is skipped on abandon.
 */
class CycleCountService
{
	public const STATUS_OPEN = 'open';
	public const STATUS_COUNTING = 'counting';
	public const STATUS_CLOSED = 'closed';

	public function __construct(
		private readonly IDBConnection $db,
		private readonly CycleCampaignMapper $campaigns,
		private readonly CycleLineMapper $lines,
		private readonly LocationMapper $locations,
		private readonly ItemMapper $items,
		private readonly BalanceMapper $balances,
		private readonly MovementService $movements,
		private readonly AccessControlService $access,
		private readonly Clock $clock,
		private readonly LocationAclService $locationAcl,
	) {
	}

	/**
	 * @return array{data: list<array<string, mixed>>, total: int, limit: int, offset: int}
	 */
	public function list(string $actorUid, ?string $status, int $limit, int $offset): array
	{
		$visible = $this->locationAcl->visibleLocationIds($actorUid);
		$result = $this->campaigns->search($status, $limit, $offset, $visible);
		return [
			'data' => array_map(static fn (CycleCampaign $c) => $c->toApi(), $result['data']),
			'total' => $result['total'],
			'limit' => $limit,
			'offset' => $offset,
		];
	}

	/** @return array<string, mixed> */
	public function get(string $actorUid, int $id): array
	{
		$camp = $this->campaigns->findById($id);
		$locationId = (int)$camp->getLocationId();
		$this->locationAcl->assertCanAccess($actorUid, $locationId);
		$api = $camp->toApi();
		$lines = [];
		$hasConflicts = false;
		foreach ($this->lines->forCampaign($id) as $l) {
			$row = $l->toApi();
			$pair = $this->balances->findPair((int)$l->getItemId(), $locationId);
			$current = $pair !== null ? $pair->getQty() : 0;
			$row['currentQty'] = $current;
			$conflict = CycleCountSemantics::hasConflict((int)$l->getSystemQty(), $current);
			$row['conflict'] = $conflict;
			if ($conflict) {
				$hasConflicts = true;
			}
			$lines[] = $row;
		}
		$api['lines'] = $lines;
		$api['hasConflicts'] = $hasConflicts;
		return $api;
	}

	/** @return array<string, mixed> */
	public function create(string $actorUid, int $locationId, string $name): array
	{
		$this->access->requireOffice($actorUid);
		$name = CodeRules::trim($name);
		if ($name === '' || mb_strlen($name) > 255) {
			throw new ValidationException('validation_failed', '', [['field' => 'name', 'code' => 'validation_failed']]);
		}
		$loc = $this->locations->findById($locationId);
		if (!$loc->getActive()) {
			throw new ValidationException('inactive_location');
		}

		$now = $this->clock->now();
		$this->db->beginTransaction();
		try {
			$camp = new CycleCampaign();
			$camp->setLocationId($locationId);
			$camp->setStatus(self::STATUS_OPEN);
			$camp->setName($name);
			$camp->setCreatedAt($now);
			$camp->setUpdatedAt($now);
			$camp->setCreatedBy($actorUid);
			$camp->setClosedAt(null);
			$camp = $this->campaigns->insert($camp);

			$activeItems = $this->items->search('', true, 100000, 0)['data'];
			foreach ($activeItems as $item) {
				// Wave C2: inventur adjusts total qty without a lot/serial —
				// lot/serial SKUs are excluded until a dedicated per-lot count
				// exists (FEFO backlog). Including them would fail closed on
				// close() with invalid_lot_code and leave campaigns unclosable.
				if ($item->getTrackMode() !== 'none') {
					continue;
				}
				$itemId = (int)$item->getId();
				$bal = $this->balances->findPair($itemId, $locationId);
				$systemQty = $bal?->getQty() ?? 0;
				$line = new CycleLine();
				$line->setCampaignId((int)$camp->getId());
				$line->setItemId($itemId);
				$line->setSystemQty($systemQty);
				$line->setQtyCounted(null);
				$line->setPostedMovId(null);
				$line->setUpdatedAt($now);
				$this->lines->insert($line);
			}
			$this->db->commit();
			return $this->get($actorUid, (int)$camp->getId());
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
	}

	/** @return array<string, mixed> */
	public function startCounting(string $actorUid, int $campaignId): array
	{
		$this->access->requireOffice($actorUid);
		$this->db->beginTransaction();
		try {
			$camp = $this->campaigns->lockById($campaignId, true);
			if (!CycleCountSemantics::canStart($camp->getStatus())) {
				throw new ConflictException('campaign_not_open');
			}
			$camp->setStatus(self::STATUS_COUNTING);
			$camp->setUpdatedAt($this->clock->now());
			$this->campaigns->update($camp);
			$this->db->commit();
			return $this->get($actorUid, $campaignId);
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
	}

	/** @return array<string, mixed> */
	public function setCount(string $actorUid, int $lineId, int $qtyCounted): array
	{
		$this->access->requireOffice($actorUid);
		if (!CycleCountSemantics::isQtyCountedValid($qtyCounted)) {
			throw new ValidationException('validation_failed', '', [['field' => 'qtyCounted', 'code' => 'validation_failed']]);
		}
		$this->db->beginTransaction();
		try {
			// Lock order matches close(): campaign → line (never line → campaign).
			// Otherwise setCount vs close ABBA-deadlocks on the same inventur.
			$peek = $this->lines->findById($lineId);
			$camp = $this->campaigns->lockById($peek->getCampaignId(), true);
			$line = $this->lines->lockById($lineId, true);
			if ((int)$line->getCampaignId() !== (int)$camp->getId()) {
				throw new ConflictException('campaign_not_counting');
			}
			if (!CycleCountSemantics::canCountOrClose($camp->getStatus())) {
				throw new ConflictException('campaign_not_counting');
			}
			if ($line->getPostedMovId() !== null) {
				throw new ConflictException('line_already_posted');
			}
			$line->setQtyCounted($qtyCounted);
			$line->setUpdatedAt($this->clock->now());
			$this->lines->update($line);
			$this->db->commit();
			return $line->toApi();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function close(
		string $actorUid,
		int $campaignId,
		bool $abandonUncounted = false,
		bool $acknowledgeConflicts = false,
	): array {
		$this->access->requireOffice($actorUid);
		$this->db->beginTransaction();
		try {
			$camp = $this->campaigns->lockById($campaignId, true);
			if (!CycleCountSemantics::canCountOrClose($camp->getStatus())) {
				throw new ConflictException('campaign_not_counting');
			}
			$lines = $this->lines->forCampaign($campaignId);
			foreach ($lines as $line) {
				if (CycleCountSemantics::isIncomplete($line->getQtyCounted(), $abandonUncounted)) {
					throw new ValidationException('count_incomplete');
				}
			}

			// Global lock protocol (must match MovementService):
			//   campaign → location → items (asc id) → balances (asc item,loc)
			// Locking balances *before* items deadlocks against concurrent
			// receive/issue/transfer which take item then balance.
			$locationId = (int)$camp->getLocationId();
			$loc = $this->locations->lockById($locationId, false);
			if (!$loc->getActive()) {
				throw new ValidationException('inactive_location');
			}
			$itemIds = [];
			foreach ($lines as $line) {
				$itemIds[(int)$line->getItemId()] = true;
			}
			$sortedItemIds = array_keys($itemIds);
			sort($sortedItemIds);
			$trackModes = [];
			foreach ($sortedItemIds as $itemId) {
				$item = $this->items->lockById($itemId, false);
				if (!$item->getActive()) {
					throw new ValidationException('inactive_item');
				}
				$trackModes[$itemId] = $item->getTrackMode();
			}

			// B1∩C2: create skipped lot/serial SKUs, but trackMode can flip mid-
			// campaign. Adjust without lotCode would throw invalid_lot_code and
			// brick close — fail closed with a clear code instead.
			$trackDetails = [];
			foreach ($lines as $line) {
				$itemId = (int)$line->getItemId();
				if (!CycleCountSemantics::isInventurEligible($trackModes[$itemId] ?? 'none')) {
					$trackDetails[] = [
						'field' => 'itemId',
						'code' => 'track_mode_changed',
						'itemId' => $itemId,
						'lineId' => (int)$line->getId(),
					];
				}
			}
			if ($trackDetails !== []) {
				throw new ValidationException('track_mode_changed', '', $trackDetails);
			}

			// Lock every campaign balance FOR UPDATE (S2 order) *before* the
			// conflict decision. Without this, a concurrent receive/issue can
			// commit between an unlocked findPair and adjust(set) and wipe
			// mid-count stock even when acknowledgeConflicts=false (TOCTOU).
			$nowSeed = $this->clock->now();
			$pairs = [];
			foreach ($lines as $line) {
				$itemId = (int)$line->getItemId();
				$this->balances->ensureZeroRow($itemId, $locationId, $nowSeed);
				$pairs[] = ['itemId' => $itemId, 'locationId' => $locationId];
			}
			$lockedBalances = $pairs === [] ? [] : $this->balances->lockPairs($pairs);

			// UC-C2: mid-count receive/issue moves live qty off the frozen snapshot.
			// Closing without acknowledgement would set-adjust counted qty and wipe
			// those movements. Fail closed until office acknowledges.
			$conflictDetails = [];
			foreach ($lines as $line) {
				$key = (int)$line->getItemId() . ':' . $locationId;
				$current = isset($lockedBalances[$key]) ? $lockedBalances[$key]->getQty() : 0;
				if (CycleCountSemantics::hasConflict((int)$line->getSystemQty(), $current)) {
					$conflictDetails[] = [
						'field' => 'lineId',
						'code' => 'count_conflict',
						'lineId' => (int)$line->getId(),
					];
				}
			}
			if (CycleCountSemantics::closeBlockedByConflicts($conflictDetails !== [], $acknowledgeConflicts)) {
				throw new ValidationException('count_conflict', '', $conflictDetails);
			}

			$posted = [];
			$notifyItemIds = [];
			foreach ($lines as $line) {
				$locked = $this->lines->lockById((int)$line->getId(), true);
				if ($locked->getPostedMovId() !== null) {
					continue;
				}
				if ($locked->getQtyCounted() === null) {
					continue; // abandoned
				}
				// Matching count → no ledger row (AdjustSemantics rejects δ=0).
				// Skipping keeps inventur close green when the shelf matches the system.
				$key = (int)$locked->getItemId() . ':' . $locationId;
				$current = isset($lockedBalances[$key]) ? $lockedBalances[$key]->getQty() : 0;
				if ((int)$locked->getQtyCounted() === $current) {
					$locked->setUpdatedAt($this->clock->now());
					$this->lines->update($locked);
					continue;
				}
				$result = $this->movements->adjust(
					$actorUid,
					$locked->getItemId(),
					$locationId,
					'set',
					$locked->getQtyCounted(),
					null,
					'Cycle count #' . $campaignId,
					null,
					false, // defer low-stock notify until after inventur TX commits
				);
				$movId = (int)($result['movements'][0]['id'] ?? 0);
				$locked->setPostedMovId($movId > 0 ? $movId : null);
				$locked->setUpdatedAt($this->clock->now());
				$this->lines->update($locked);
				// Keep local map coherent if a later line somehow shared a pair.
				if (isset($lockedBalances[$key])) {
					$lockedBalances[$key]->setQty((int)$locked->getQtyCounted());
				}
				$posted[] = $movId;
				$notifyItemIds[(int)$locked->getItemId()] = true;
			}

			$now = $this->clock->now();
			$camp->setStatus(self::STATUS_CLOSED);
			$camp->setClosedAt($now);
			$camp->setUpdatedAt($now);
			$this->campaigns->update($camp);
			$this->db->commit();
			foreach (array_keys($notifyItemIds) as $itemId) {
				$this->movements->notifyLowStockAfterChange($itemId);
			}
			$api = $this->get($actorUid, $campaignId);
			$api['postedMovementIds'] = $posted;
			return $api;
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
	}
}
