<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Db\Item;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\UniqueViolation;
use OCA\InventoryCheck\Exception\ConflictException;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

class ItemService
{
	/**
	 * Serialises all sku/scan_code mutations so the S7 cross-field
	 * uniqueness check cannot be raced past by a concurrent writer
	 * (the DB has no cross-field constraint to fall back on).
	 */
	private const CODES_LOCK = 'inventorycheck/item_codes';

	public function __construct(
		private readonly IDBConnection $db,
		private readonly ItemMapper $items,
		private readonly BalanceMapper $balances,
		private readonly AccessControlService $access,
		private readonly Clock $clock,
		private readonly ILockingProvider $locking,
	) {
	}

	/** @return array{data: list<array<string, mixed>>, total: int, limit: int, offset: int} */
	public function list(string $q, ?bool $active, bool $lowStock, int $limit, int $offset): array
	{
		$idFilter = null;
		if ($lowStock) {
			$idFilter = $this->lowStockItemIds();
			if ($idFilter === []) {
				return ['data' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset];
			}
		}
		$result = $this->items->search($q, $active, $limit, $offset, $idFilter);
		return [
			'data' => array_map(static fn (Item $i) => $i->toApi(), $result['data']),
			'total' => $result['total'],
			'limit' => $limit,
			'offset' => $offset,
		];
	}

	/**
	 * S10 predicate over all active items — ids currently below reorder level.
	 *
	 * @return list<int>
	 */
	private function lowStockItemIds(): array
	{
		$sums = $this->balances->sumQtyByItem();
		$all = $this->items->search('', true, 100000, 0);
		$ids = [];
		foreach ($all['data'] as $item) {
			$total = $sums[(int)$item->getId()] ?? 0;
			if (LowStockQuery::isLowStock(true, $item->getReorderLevel(), $total)) {
				$ids[] = (int)$item->getId();
			}
		}
		return $ids;
	}

	/** @return array<string, mixed> */
	public function get(int $id): array
	{
		return $this->items->findById($id)->toApi();
	}

	/**
	 * S8 by-code with per-location balances.
	 * @return array<string, mixed>
	 */
	public function byCode(string $code): array
	{
		$code = CodeRules::trim($code);
		$item = $this->items->resolveByCode($code);
		if ($item === null) {
			throw new NotFoundException('code_not_found');
		}
		$api = $item->toApi();
		$bal = $this->balances->search((int)$item->getId(), null, false, 200, 0);
		$api['balances'] = array_map(static fn ($b) => $b->toApi(), $bal['data']);
		return $api;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function create(string $actorUid, array $input): array
	{
		$this->access->requireOffice($actorUid);
		$sku = CodeRules::trim((string)($input['sku'] ?? ''));
		$scan = CodeRules::trim((string)($input['scanCode'] ?? $input['scan_code'] ?? ''));
		if ($scan === '') {
			$scan = $sku;
		}
		$name = CodeRules::trim((string)($input['name'] ?? ''));
		$uom = CodeRules::trim((string)($input['uom'] ?? 'pcs'));
		if ($uom === '') {
			$uom = 'pcs';
		}
		$desc = isset($input['description']) ? CodeRules::trim((string)$input['description']) : null;
		if ($desc === '') {
			$desc = null;
		}
		$reorder = isset($input['reorderLevel']) ? (int)$input['reorderLevel'] : (isset($input['reorder_level']) ? (int)$input['reorder_level'] : 0);
		$this->validateItemFields($sku, $scan, $name, $uom, $desc, $reorder);

		return $this->withCodesLock(function () use ($actorUid, $sku, $scan, $name, $uom, $desc, $reorder): array {
			if (CodeRules::conflictsWithOthers(null, $sku, $scan, $this->items->allCodePairs())) {
				throw new ConflictException('code_exists');
			}
			$now = $this->clock->now();
			$item = new Item();
			$item->setSku($sku);
			$item->setScanCode($scan);
			$item->setName($name);
			$item->setDescription($desc);
			$item->setUom($uom);
			$item->setReorderLevel($reorder);
			$item->setActive(true);
			$item->setCreatedAt($now);
			$item->setUpdatedAt($now);
			$item->setCreatedBy($actorUid);
			try {
				return $this->items->insert($item)->toApi();
			} catch (\Throwable $e) {
				throw UniqueViolation::is($e) ? new ConflictException('code_exists') : $e;
			}
		});
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function update(string $actorUid, int $id, array $input): array
	{
		$this->access->requireOffice($actorUid);
		$touchesCodes = array_key_exists('sku', $input)
			|| array_key_exists('scanCode', $input)
			|| array_key_exists('scan_code', $input);

		$run = fn (): array => $this->updateLocked($actorUid, $id, $input);
		return $touchesCodes ? $this->withCodesLock($run) : $run();
	}

	public function delete(string $actorUid, int $id): void
	{
		$this->access->requireOffice($actorUid);
		$this->db->beginTransaction();
		try {
			// Exclusive lock conflicts with the shared lock held by any
			// in-flight movement, so the reference count is authoritative (S6).
			$item = $this->items->lockById($id, true);
			if ($this->items->countMovementsReferencing($id) > 0) {
				throw new ConflictException('item_has_movements');
			}
			$this->items->delete($item);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/** @return array<string, mixed> */
	private function updateLocked(string $actorUid, int $id, array $input): array
	{
		unset($actorUid);
		$this->db->beginTransaction();
		try {
			// Exclusive lock: serialises against movements' shared item lock,
			// so the zero-balance check below cannot race a posting (S5).
			$item = $this->items->lockById($id, true);
			$sku = $item->getSku();
			$scan = $item->getScanCode();
			if (array_key_exists('sku', $input)) {
				$sku = CodeRules::trim((string)$input['sku']);
			}
			if (array_key_exists('scanCode', $input) || array_key_exists('scan_code', $input)) {
				$scan = CodeRules::trim((string)($input['scanCode'] ?? $input['scan_code']));
			}
			if (array_key_exists('name', $input)) {
				$name = CodeRules::trim((string)$input['name']);
				if ($name === '' || mb_strlen($name) > 255) {
					throw new ValidationException('validation_failed', '', [['field' => 'name', 'code' => 'name_required']]);
				}
				$item->setName($name);
			}
			if (array_key_exists('description', $input)) {
				$desc = $input['description'] === null ? null : CodeRules::trim((string)$input['description']);
				if ($desc !== null && mb_strlen($desc) > 10000) {
					throw new ValidationException('validation_failed', '', [['field' => 'description', 'code' => 'validation_failed']]);
				}
				$item->setDescription($desc === '' ? null : $desc);
			}
			if (array_key_exists('uom', $input)) {
				$uom = CodeRules::trim((string)$input['uom']);
				if ($uom === '' || mb_strlen($uom) > 32) {
					throw new ValidationException('validation_failed', '', [['field' => 'uom', 'code' => 'validation_failed']]);
				}
				$item->setUom($uom);
			}
			if (array_key_exists('reorderLevel', $input) || array_key_exists('reorder_level', $input)) {
				$reorder = (int)($input['reorderLevel'] ?? $input['reorder_level']);
				if ($reorder < 0 || $reorder > 1000000) {
					throw new ValidationException('validation_failed', '', [['field' => 'reorderLevel', 'code' => 'validation_failed']]);
				}
				$item->setReorderLevel($reorder);
			}
			if ($sku !== $item->getSku() || $scan !== $item->getScanCode()) {
				if (!CodeRules::isValidSku($sku) || !CodeRules::isValidScanCode($scan)) {
					throw new ValidationException('invalid_code_format');
				}
				if (CodeRules::conflictsWithOthers($id, $sku, $scan, $this->items->allCodePairs())) {
					throw new ConflictException('code_exists');
				}
				$item->setSku($sku);
				$item->setScanCode($scan);
			}
			if (array_key_exists('active', $input)) {
				$active = (bool)$input['active'];
				if (!$active && $item->getActive() && $this->items->hasNonZeroBalance($id)) {
					throw new ConflictException('item_has_stock');
				}
				$item->setActive($active);
			}
			$item->setUpdatedAt($this->clock->now());
			$api = $this->items->update($item)->toApi();
			$this->db->commit();
			return $api;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw UniqueViolation::is($e) ? new ConflictException('code_exists') : $e;
		}
	}

	private function validateItemFields(string $sku, string $scan, string $name, string $uom, ?string $desc, int $reorder): void
	{
		if (!CodeRules::isValidSku($sku) || !CodeRules::isValidScanCode($scan)) {
			throw new ValidationException('invalid_code_format');
		}
		if ($name === '' || mb_strlen($name) > 255) {
			throw new ValidationException('validation_failed', '', [['field' => 'name', 'code' => 'name_required']]);
		}
		if (mb_strlen($uom) < 1 || mb_strlen($uom) > 32) {
			throw new ValidationException('validation_failed', '', [['field' => 'uom', 'code' => 'validation_failed']]);
		}
		if ($desc !== null && mb_strlen($desc) > 10000) {
			throw new ValidationException('validation_failed', '', [['field' => 'description', 'code' => 'validation_failed']]);
		}
		if ($reorder < 0 || $reorder > 1000000) {
			throw new ValidationException('validation_failed', '', [['field' => 'reorderLevel', 'code' => 'validation_failed']]);
		}
	}

	/**
	 * @template T
	 * @param callable(): T $fn
	 * @return T
	 */
	private function withCodesLock(callable $fn): mixed
	{
		$attempts = 0;
		while (true) {
			try {
				$this->locking->acquireLock(self::CODES_LOCK, ILockingProvider::LOCK_EXCLUSIVE);
				break;
			} catch (LockedException) {
				if (++$attempts >= 40) {
					throw new ConflictException('conflict');
				}
				usleep(25_000);
			}
		}
		try {
			return $fn();
		} finally {
			$this->locking->releaseLock(self::CODES_LOCK, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
}
