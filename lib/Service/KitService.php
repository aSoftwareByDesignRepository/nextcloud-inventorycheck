<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCP\IConfig;
use OCP\IDBConnection;

/**
 * F-17 procedure kits — a kit is a named bundle; issuing it books every
 * line as a movement in ONE transaction (partial kit issues are rejected
 * atomically so a kit never half-books).
 */
class KitService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly ItemMapper $items,
		private readonly MovementService $movements,
		private readonly AccessControlService $access,
		private readonly IConfig $config,
	) {
	}

	/** @return list<array{id:int,name:string,note:?string,lines:list<array{itemId:int,itemName:string,sku:string,qty:int|string>}> */
	public function list(string $actorUid): array
	{
		$this->access->requireAppUser($actorUid);
		$out = [];
		foreach ($this->kits() as $kit) {
			$lines = [];
			foreach ($this->linesFor($kit['id']) as $l) {
				$item = $this->items->findById($l['item_id']);
				$lines[] = [
					'itemId' => $l['item_id'],
					'itemName' => $item->getName(),
					'sku' => $item->getSku(),
					'qty' => QtyScale::toDisplay($this->config, $l['qty']),
				];
			}
			$out[] = ['id' => $kit['id'], 'name' => $kit['name'], 'note' => $kit['note'], 'lines' => $lines];
		}
		return $out;
	}

	/** Issue every kit line at a location — one TX, all-or-nothing. */
	public function issue(string $actorUid, int $kitId, int $locationId, ?string $reason): void
	{
		$kit = $this->kitById($kitId);
		if ($kit === null || !$kit['active']) {
			throw new NotFoundException('unknown_kit');
		}
		$lines = $this->linesFor($kitId);
		if ($lines === []) {
			throw new ValidationException('kit_has_no_lines');
		}
		$this->db->beginTransaction();
		try {
			foreach ($lines as $l) {
				$this->movements->issue($actorUid, $l['item_id'], $locationId, $l['qty'], $reason ?? $kit['name']);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	public function create(string $actorUid, string $name, ?string $note, array $linesIn): int
	{
		$this->access->requireOffice($actorUid);
		$name = mb_substr(trim($name), 0, 200);
		if ($name === '' || count($linesIn) === 0 || count($linesIn) > 100) {
			throw new ValidationException('validation_failed', '', [['field' => 'lines', 'code' => 'validation_failed']]);
		}
		// Validate + dedupe every line before the first write — a mid-loop
		// failure must not leave a persisted kit head with partial lines.
		$seen = [];
		$lines = [];
		foreach ($linesIn as $l) {
			$itemId = (int)($l['itemId'] ?? 0);
			$qty = (int)($l['qty'] ?? 0);
			if ($itemId <= 0 || isset($seen[$itemId])) {
				continue;
			}
			$this->items->findById($itemId);
			if ($qty < 1 || $qty > QtyScale::maxStorage($this->config)) {
				throw new ValidationException('validation_failed', '', [['field' => 'qty', 'code' => 'validation_failed']]);
			}
			$lines[] = ['itemId' => $itemId, 'qty' => $qty];
			$seen[$itemId] = true;
		}
		if ($lines === []) {
			throw new ValidationException('validation_failed', '', [['field' => 'lines', 'code' => 'validation_failed']]);
		}
		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('iv_kits')
				->setValue('name', $qb->createNamedParameter($name))
				->setValue('note', $qb->createNamedParameter($note !== null ? mb_substr($note, 0, 512) : null))
				->setValue('active', $qb->createNamedParameter(true, \PDO::PARAM_BOOL));
			$qb->executeStatement();
			$kitId = (int)$this->db->lastInsertId('iv_kits');
			foreach ($lines as $l) {
				$qb = $this->db->getQueryBuilder();
				$qb->insert('iv_kit_lines')
					->setValue('kit_id', $qb->createNamedParameter($kitId, \PDO::PARAM_INT))
					->setValue('item_id', $qb->createNamedParameter($l['itemId'], \PDO::PARAM_INT))
					->setValue('qty', $qb->createNamedParameter($l['qty'], \PDO::PARAM_INT));
				$qb->executeStatement();
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $kitId;
	}

	/** @return list<array{id:int,name:string,note:?string,active:bool}> */
	private function kits(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'name', 'note', 'active')->from('iv_kits')
			->where($qb->expr()->eq('active', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)))
			->orderBy('name', 'ASC');
		$res = $qb->executeQuery();
		$out = [];
		while ($r = $res->fetch()) {
			$out[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'note' => $r['note'], 'active' => (bool)$r['active']];
		}
		$res->closeCursor();
		return $out;
	}

	private function kitById(int $id): ?array
	{
		foreach ($this->kits() as $k) {
			if ($k['id'] === $id) {
				return $k;
			}
		}
		return null;
	}

	/** @return list<array{item_id:int,qty:int}> */
	private function linesFor(int $kitId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('item_id', 'qty')->from('iv_kit_lines')
			->where($qb->expr()->eq('kit_id', $qb->createNamedParameter($kitId, \PDO::PARAM_INT)))
			->orderBy('id', 'ASC');
		$res = $qb->executeQuery();
		$out = [];
		while ($r = $res->fetch()) {
			$out[] = ['item_id' => (int)$r['item_id'], 'qty' => (int)$r['qty']];
		}
		$res->closeCursor();
		return $out;
	}
}
