<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Db\CycleLineMapper;
use OCA\InventoryCheck\Db\Item;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\LocationMapper;
use OCA\InventoryCheck\Db\UniqueViolation;
use OCA\InventoryCheck\Exception\ConflictException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Util\Csv;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * CSV item import with dry-run and atomic commit (Wave A2).
 *
 * Upsert semantics: a row whose sku/scan_code identifies exactly one
 * existing item updates it — columns absent from the file keep their
 * stored values, codes and stock are never rewritten by an update row.
 * Rows matching nothing are created; rows matching two different items
 * stay an error (never merge blindly).
 *
 * Opening balances post as receive movements on create only — ledger
 * invariant preserved, re-imports cannot double-post stock.
 * Wave C1: reorder_level and opening_qty are display values converted to
 * storage via {@see QtyScale::toStorage}.
 */
class CsvImportService
{
	/** matchExistingItemId sentinel: row's codes point at two different items. */
	private const MATCH_AMBIGUOUS = -1;
	/**
	 * Canonical column keys validateAll actually reads after
	 * {@see Csv::canonicalizeHeader}. A detected header mapping to anything
	 * outside this set is ignored-informational — surfaced in dryRun's
	 * header annotations, never silently read.
	 *
	 * @var list<string>
	 */
	public const CONSUMED_COLUMNS = [
		'sku',
		'scan_code',
		'name',
		'description',
		'uom',
		'reorder_level',
		'target_stock',
		'active',
		'supplier_note',
		'last_price_minor',
		'opening_location_code',
		'opening_qty',
		'order_pack_qty',
		'order_pack_price_minor',
		'supplier_lead_days',
		'supplier_name',
		'supplier_sku',
		'category',
		'criticality',
	];

	public function __construct(
		private readonly IDBConnection $db,
		private readonly ItemMapper $items,
		private readonly LocationMapper $locations,
		private readonly MovementService $movements,
		private readonly AccessControlService $access,
		private readonly Clock $clock,
		private readonly ILockingProvider $locking,
		private readonly IConfig $config,
		private readonly CycleLineMapper $cycleLines,
	) {
	}

	/**
	 * @return array{ok: int, updates: int, errors: list<array{line: int, code: string, message: string}>, headers: list<array{raw: string, field: string, ignored: bool}>}
	 */
	public function dryRun(string $actorUid, string $csvText): array
	{
		$this->access->requireOffice($actorUid);
		$report = $this->validateAll($csvText, false);
		$headers = array_map(
			static fn (array $h): array => $h + ['ignored' => !in_array($h['field'], self::CONSUMED_COLUMNS, true)],
			Csv::headerMap($csvText),
		);
		return [
			'ok' => $report['ok'],
			'updates' => $report['updates'],
			'errors' => $report['errors'],
			'headers' => $headers,
		];
	}

	/**
	 * @return array{ok: int, created: int, updated: int, received: int, skipped: int, errors: list<array{line: int, code: string, message: string}>}
	 */
	public function commit(string $actorUid, string $csvText, bool $skipErrors = false): array
	{
		$this->access->requireOffice($actorUid);
		$report = $this->validateAll($csvText, true);
		if ($report['errors'] !== [] && !$skipErrors) {
			return [
				'ok' => 0,
				'created' => 0,
				'updated' => 0,
				'received' => 0,
				'skipped' => 0,
				'errors' => $report['errors'],
			];
		}

		$created = 0;
		$updated = 0;
		$received = 0;
		$skipped = count($report['errors']);
		/** @var list<array{sku: string, scan_code: string, name: string, description: ?string, uom: string, reorder_level: int, active: bool, supplier_note: ?string, last_price_minor: ?int, opening_qty: ?int, opening_location_id: ?int, update_item_id: ?int, update_input: array<string, string|int|bool|null>}> $rows */
		$rows = $report['rows'] ?? [];
		/** @var list<int> $notifyItemIds */
		$notifyItemIds = [];

		$this->withCodesLock(function () use ($actorUid, $rows, &$created, &$updated, &$received, &$notifyItemIds): void {
			$this->db->beginTransaction();
			try {
				foreach ($rows as $row) {
					$now = $this->clock->now();
					if ($row['update_item_id'] !== null) {
						$this->applyUpdateRow($row, $now);
						$updated++;
						continue;
					}
					$item = new Item();
					$item->setSku($row['sku']);
					$item->setScanCode($row['scan_code']);
					$item->setName($row['name']);
					$item->setDescription($row['description']);
					$item->setUom($row['uom']);
					$item->setReorderLevel($row['reorder_level']);
					$item->setTargetStock($row['target_stock'] ?? null);
					$item->setActive($row['active']);
					$item->setCreatedAt($now);
					$item->setUpdatedAt($now);
					$item->setCreatedBy($actorUid);
					$item->setSupplierNote($row['supplier_note']);
					$item->setLastPriceMinor($row['last_price_minor']);
					$item->setOrderPackQty($row['order_pack_qty'] ?? null);
					$item->setOrderPackPriceMinor($row['order_pack_price_minor'] ?? null);
					$item->setSupplierName($row['supplier_name'] ?? null);
					$item->setSupplierSku($row['supplier_sku'] ?? null);
					$item->setSupplierLeadDays($row['supplier_lead_days'] ?? null);
					$item->setCategory($row['category'] ?? null);
					$item->setCriticality($row['criticality'] ?? 'none');
					try {
						$item = $this->items->insert($item);
					} catch (\Throwable $e) {
						throw UniqueViolation::is($e) ? new ConflictException('code_exists') : $e;
					}
					$created++;
					if ($row['opening_qty'] !== null && $row['opening_location_id'] !== null) {
						// Defer low-stock notify until after the outer import TX
						// commits — same class as inventur/flange (no phantoms).
						$this->movements->receive(
							$actorUid,
							(int)$item->getId(),
							(int)$row['opening_location_id'],
							(int)$row['opening_qty'],
							'CSV opening balance',
							null,
							false,
						);
						$notifyItemIds[] = (int)$item->getId();
						$received++;
					}
				}
				$this->db->commit();
			} catch (\Throwable $e) {
				if ($this->db->inTransaction()) {
					$this->db->rollBack();
				}
				throw $e;
			}
		});

		foreach (array_unique($notifyItemIds) as $itemId) {
			$this->movements->notifyLowStockAfterChange($itemId);
		}

		return [
			'ok' => count($rows),
			'created' => $created,
			'updated' => $updated,
			'received' => $received,
			'skipped' => $skipped,
			'errors' => $skipErrors ? $report['errors'] : [],
		];
	}

	/**
	 * Update path for an import row matched to an existing item. Writes only
	 * the columns the file actually carries via a targeted UPDATE, under the
	 * item's exclusive row lock so the deactivation guard re-check is
	 * authoritative (validate-time was only a dry-run preview).
	 *
	 * @param array{update_item_id: ?int, sku: string, scan_code: string, update_input: array<string, string|int|bool|null>} $row
	 */
	private function applyUpdateRow(array $row, int $now): void
	{
		$itemId = (int)$row['update_item_id'];
		$item = $this->items->lockById($itemId, true);
		// Re-verify the match under the lock: the row's identity resolved
		// pre-lock, so a concurrent code edit must not turn this update into
		// a write on an item the codes no longer point at.
		if ($item->getSku() !== $row['sku'] && $item->getScanCode() !== $row['sku']
			&& $item->getSku() !== $row['scan_code'] && $item->getScanCode() !== $row['scan_code']) {
			throw new ConflictException('conflict');
		}
		$cols = $row['update_input'];
		if (($cols['active'] ?? true) === false && $item->getActive()) {
			if ($this->items->hasNonZeroBalance($itemId)) {
				throw new ConflictException('item_has_stock');
			}
			if ($this->cycleLines->countOpenCampaignsForItem($itemId) > 0) {
				throw new ConflictException('item_in_open_stocktake');
			}
		}
		$cols['updated_at'] = $now;
		$this->items->updateColumns($itemId, $cols);
	}

	/**
	 * Upsert matching: the existing item whose sku or scan_code equals the
	 * row's sku or scan_code. Returns the item id, null when nothing matches
	 * (create path), or {@see MATCH_AMBIGUOUS} when the row's two codes point
	 * at two different items — merging is never guessed.
	 *
	 * @param list<array{id: int, sku: string, scanCode: string}> $existing
	 */
	private function matchExistingItemId(string $sku, string $scan, array $existing): ?int
	{
		$ids = [];
		foreach ($existing as $row) {
			if ((int)$row['id'] === 0) {
				continue; // pseudo-row appended from an earlier accepted file line
			}
			if ($row['sku'] === $sku || $row['scanCode'] === $sku
				|| $row['sku'] === $scan || $row['scanCode'] === $scan) {
				$ids[(int)$row['id']] = true;
			}
		}
		if (count($ids) > 1) {
			return self::MATCH_AMBIGUOUS;
		}
		return $ids === [] ? null : (int)array_key_first($ids);
	}

	/**
	 * @return array{
	 *   ok: int,
	 *   updates: int,
	 *   errors: list<array{line: int, code: string, message: string}>,
	 *   rows?: list<array{sku: string, scan_code: string, name: string, description: ?string, uom: string, reorder_level: int, active: bool, supplier_note: ?string, last_price_minor: ?int, opening_qty: ?int, opening_location_id: ?int, update_item_id: ?int, update_input: array<string, string|int|bool|null>}>
	 * }
	 */
	private function validateAll(string $csvText, bool $includeRows): array
	{
		$parsed = Csv::parse($csvText);
		if ($parsed === []) {
			throw new ValidationException('validation_failed', 'CSV has no data rows.', [
				['field' => 'csv', 'code' => 'empty'],
			]);
		}
		if (count($parsed) > Csv::MAX_IMPORT_ROWS) {
			throw new ValidationException('validation_failed', 'Too many rows.', [
				['field' => 'csv', 'code' => 'too_many_rows'],
			]);
		}

		$existing = $this->items->allCodePairs();
		$seenSku = [];
		$seenScan = [];
		$seenIds = [];
		$errors = [];
		$rows = [];
		$updates = 0;

		foreach ($parsed as $i => $raw) {
			$line = $i + 2;
			$sku = CodeRules::trim((string)($raw['sku'] ?? ''));
			$scan = CodeRules::trim((string)($raw['scan_code'] ?? $raw['scancode'] ?? ''));
			if ($scan === '') {
				$scan = $sku;
			}
			$name = CodeRules::trim((string)($raw['name'] ?? ''));
			$uom = CodeRules::trim((string)($raw['uom'] ?? 'pcs'));
			if ($uom === '') {
				$uom = 'pcs';
			}
			if (mb_strlen($uom) > 32) {
				$errors[] = ['line' => $line, 'code' => 'invalid_uom', 'message' => 'uom too long'];
				continue;
			}
			$desc = CodeRules::trim((string)($raw['description'] ?? ''));
			$desc = $desc === '' ? null : $desc;
			if ($desc !== null && mb_strlen($desc) > 10000) {
				$errors[] = ['line' => $line, 'code' => 'invalid_description', 'message' => 'description too long'];
				continue;
			}
			$supplierNote = CodeRules::trim((string)($raw['supplier_note'] ?? $raw['lieferant'] ?? ''));
			$supplierNote = $supplierNote === '' ? null : $supplierNote;
			if ($supplierNote !== null && mb_strlen($supplierNote) > 255) {
				$errors[] = ['line' => $line, 'code' => 'invalid_supplier_note', 'message' => 'supplier_note too long'];
				continue;
			}
			$lastPriceRaw = $raw['last_price_minor'] ?? '';
			$lastPriceMinor = null;
			if ($lastPriceRaw !== '') {
				if (!preg_match('/^-?\d+$/', (string)$lastPriceRaw)) {
					$errors[] = ['line' => $line, 'code' => 'invalid_last_price', 'message' => 'last_price_minor must be an integer'];
					continue;
				}
				$lastPriceMinor = (int)$lastPriceRaw;
				if ($lastPriceMinor < 0 || $lastPriceMinor > 100_000_000) {
					$errors[] = ['line' => $line, 'code' => 'invalid_last_price', 'message' => 'last_price_minor must be ≥ 0 and ≤ 100000000'];
					continue;
				}
			}
			$activeRaw = strtolower(trim((string)($raw['active'] ?? '1')));
			$active = !in_array($activeRaw, ['0', 'false', 'no', 'nein', 'inactive'], true);

			$reorderRaw = $raw['reorder_level'] ?? $raw['reorderlevel'] ?? '0';
			try {
				$reorder = QtyScale::toStorage($this->config, $reorderRaw === '' ? '0' : $reorderRaw);
			} catch (ValidationException) {
				$errors[] = ['line' => $line, 'code' => 'invalid_reorder', 'message' => 'reorder_level out of range'];
				continue;
			}
			$targetStock = null;
			$targetRaw = $raw['target_stock'] ?? '';
			if ($targetRaw !== '') {
				try {
					$targetStock = QtyScale::toStorage($this->config, $targetRaw);
				} catch (ValidationException) {
					$errors[] = ['line' => $line, 'code' => 'invalid_target', 'message' => 'target_stock out of range'];
					continue;
				}
				if ($targetStock < 0 || $targetStock > QtyScale::maxStorage($this->config) || $targetStock < $reorder) {
					$errors[] = ['line' => $line, 'code' => 'invalid_target', 'message' => 'target_stock out of range or below reorder_level'];
					continue;
				}
			}

			if (!CodeRules::isValidSku($sku) || !CodeRules::isValidScanCode($scan)) {
				$errors[] = ['line' => $line, 'code' => 'invalid_code_format', 'message' => 'Invalid sku or scan_code'];
				continue;
			}
			if ($reorder < 0 || $reorder > QtyScale::maxStorage($this->config)) {
				$errors[] = ['line' => $line, 'code' => 'invalid_reorder', 'message' => 'reorder_level out of range'];
				continue;
			}
			if (isset($seenSku[$sku]) || isset($seenScan[$scan]) || isset($seenSku[$scan]) || isset($seenScan[$sku])) {
				$errors[] = ['line' => $line, 'code' => 'code_exists', 'message' => 'Duplicate sku/scan_code in file'];
				continue;
			}
			// Upsert: a row whose codes identify exactly one existing item
			// updates it. Codes pointing at two different items — or a second
			// row targeting an item another row already updates — stay errors:
			// silently picking a winner would be a blind merge.
			$updateItemId = $this->matchExistingItemId($sku, $scan, $existing);
			if ($updateItemId === self::MATCH_AMBIGUOUS) {
				$errors[] = ['line' => $line, 'code' => 'code_exists', 'message' => 'Codes match more than one existing item'];
				continue;
			}
			if ($updateItemId !== null && isset($seenIds[$updateItemId])) {
				$errors[] = ['line' => $line, 'code' => 'code_exists', 'message' => 'Another row already updates this item'];
				continue;
			}
			if ($updateItemId === null) {
				if (CodeRules::conflictsWithOthers(null, $sku, $scan, $existing)) {
					$errors[] = ['line' => $line, 'code' => 'code_exists', 'message' => 'sku or scan_code already exists'];
					continue;
				}
				if (CodeRules::conflictsWithLocationCodes($sku, $scan, $this->locations->allCodes())) {
					$errors[] = ['line' => $line, 'code' => 'code_exists', 'message' => 'sku or scan_code collides with a location code'];
					continue;
				}
			}
			// Name is required to create; on update rows only a *present*
			// name column must be non-empty — a minimal "sku;category" file
			// can update without restating every column.
			if (($updateItemId === null || array_key_exists('name', $raw))
				&& ($name === '' || mb_strlen($name) > 255)) {
				$errors[] = ['line' => $line, 'code' => 'name_required', 'message' => 'Name is required'];
				continue;
			}

			$openingLocCode = CodeRules::trim((string)($raw['opening_location_code'] ?? ''));
			$openingQtyRaw = $raw['opening_qty'] ?? '';
			$openingQty = null;
			$openingLocId = null;
			// Opening balances are create-only — on update rows they are
			// ignored entirely so a re-import can never double-post stock.
			if ($updateItemId === null && ($openingLocCode !== '' || $openingQtyRaw !== '')) {
				if ($openingLocCode === '' || $openingQtyRaw === '') {
					$errors[] = ['line' => $line, 'code' => 'invalid_opening', 'message' => 'opening_location_code and opening_qty must both be set'];
					continue;
				}
				try {
					$openingQty = QtyScale::toStorage($this->config, $openingQtyRaw);
				} catch (ValidationException) {
					$errors[] = ['line' => $line, 'code' => 'invalid_opening', 'message' => 'opening_qty must be 1…1000000'];
					continue;
				}
				if ($openingQty < 1 || $openingQty > QtyScale::maxStorage($this->config)) {
					$errors[] = ['line' => $line, 'code' => 'invalid_opening', 'message' => 'opening_qty must be 1…1000000'];
					continue;
				}
				$loc = $this->locations->findByCode($openingLocCode);
				if ($loc === null || !$loc->getActive()) {
					$errors[] = ['line' => $line, 'code' => 'unknown_location', 'message' => 'opening location not found'];
					continue;
				}
				$openingLocId = (int)$loc->getId();
			}

			// F-01/F-03/F-09/F-19 appended restock columns (all optional).
			$orderPackQty = $this->parseImportInt($raw, 'order_pack_qty', 1, QtyScale::maxStorage($this->config), 'invalid_pack', $errors, $line);
			if ($orderPackQty === false) {
				continue;
			}
			$orderPackPrice = $this->parseImportInt($raw, 'order_pack_price_minor', 0, RestockConfig::MAX_MONEY_MINOR, 'invalid_pack_price', $errors, $line);
			if ($orderPackPrice === false) {
				continue;
			}
			$leadDays = $this->parseImportInt($raw, 'supplier_lead_days', 0, 365, 'invalid_lead_days', $errors, $line);
			if ($leadDays === false) {
				continue;
			}
			$supplierName = CodeRules::trim((string)($raw['supplier_name'] ?? ''));
			if (mb_strlen($supplierName) > 200) {
				$errors[] = ['line' => $line, 'code' => 'invalid_supplier_name', 'message' => 'supplier_name too long'];
				continue;
			}
			$supplierSku = CodeRules::trim((string)($raw['supplier_sku'] ?? ''));
			if (mb_strlen($supplierSku) > 200) {
				$errors[] = ['line' => $line, 'code' => 'invalid_supplier_sku', 'message' => 'supplier_sku too long'];
				continue;
			}
			$category = CodeRules::trim((string)($raw['category'] ?? ''));
			if (mb_strlen($category) > 100) {
				$errors[] = ['line' => $line, 'code' => 'invalid_category', 'message' => 'category too long'];
				continue;
			}
			$criticality = strtolower(CodeRules::trim((string)($raw['criticality'] ?? 'none')));
			if (!in_array($criticality, ['none', 'critical'], true)) {
				$errors[] = ['line' => $line, 'code' => 'invalid_criticality', 'message' => 'criticality must be none|critical'];
				continue;
			}

			$updateInput = [];
			if ($updateItemId !== null) {
				$matched = $this->items->findById($updateItemId);
				// No reorder_level column → the stored level stays, so the
				// target_stock floor is the item's real reorder level, not a
				// phantom 0 from the defaulted row parse.
				if (!array_key_exists('reorder_level', $raw) && array_key_exists('target_stock', $raw)
					&& $targetStock !== null && $targetStock < $matched->getReorderLevel()) {
					$errors[] = ['line' => $line, 'code' => 'invalid_target', 'message' => 'target_stock out of range or below reorder_level'];
					continue;
				}
				// Same deactivation guards as ItemService::update — per-row
				// here so dry-run predicts them and skipErrors can skip just
				// the blocked lines; re-checked under the row lock at commit.
				if (array_key_exists('active', $raw) && !$active && $matched->getActive()) {
					if ($this->items->hasNonZeroBalance($updateItemId)) {
						$errors[] = ['line' => $line, 'code' => 'item_has_stock', 'message' => 'Item still has stock — cannot deactivate'];
						continue;
					}
					if ($this->cycleLines->countOpenCampaignsForItem($updateItemId) > 0) {
						$errors[] = ['line' => $line, 'code' => 'item_in_open_stocktake', 'message' => 'Item is in an open stocktake — cannot deactivate'];
						continue;
					}
				}
				// Column-presence merge: only columns the file actually
				// carries are applied — absent columns keep stored values,
				// present-but-empty cells clear nullable fields.
				$present = [
					'name' => $name,
					'description' => $desc,
					'uom' => $uom,
					'reorder_level' => $reorder,
					'target_stock' => $targetStock,
					'active' => $active,
					'supplier_note' => $supplierNote,
					'last_price_minor' => $lastPriceMinor,
					'order_pack_qty' => $orderPackQty ?: null,
					'order_pack_price_minor' => $orderPackPrice ?: null,
					'supplier_name' => $supplierName !== '' ? $supplierName : null,
					'supplier_sku' => $supplierSku !== '' ? $supplierSku : null,
					'supplier_lead_days' => $leadDays ?: null,
					'category' => $category !== '' ? $category : null,
					'criticality' => $criticality,
				];
				foreach ($present as $col => $value) {
					if (array_key_exists($col, $raw)) {
						$updateInput[$col] = $value;
					}
				}
			}

			$seenSku[$sku] = true;
			$seenScan[$scan] = true;
			if ($updateItemId !== null) {
				$seenIds[$updateItemId] = true;
				$updates++;
			}
			$existing[] = ['id' => 0, 'sku' => $sku, 'scanCode' => $scan];
			$rows[] = [
				'sku' => $sku,
				'scan_code' => $scan,
				'name' => $name,
				'description' => $desc,
				'uom' => $uom,
				'reorder_level' => $reorder,
				'target_stock' => $targetStock,
				'active' => $active,
				'supplier_note' => $supplierNote,
				'last_price_minor' => $lastPriceMinor,
				'opening_qty' => $openingQty,
				'opening_location_id' => $openingLocId,
				'order_pack_qty' => $orderPackQty ?: null,
				'order_pack_price_minor' => $orderPackPrice ?: null,
				'supplier_name' => $supplierName !== '' ? $supplierName : null,
				'supplier_sku' => $supplierSku !== '' ? $supplierSku : null,
				'supplier_lead_days' => $leadDays ?: null,
				'category' => $category !== '' ? $category : null,
				'criticality' => $criticality,
				'update_item_id' => $updateItemId,
				'update_input' => $updateInput,
			];
		}

		$out = [
			'ok' => count($rows),
			'updates' => $updates,
			'errors' => $errors,
		];
		if ($includeRows) {
			$out['rows'] = $rows;
		}
		return $out;
	}

	/**
	 * Optional bounded integer column — returns int|null, or false after
	 * recording a per-row error.
	 *
	 * @param array<string, string> $raw
	 * @param list<array{line: int, code: string, message: string}> $errors
	 * @return int|false|null
	 */
	private function parseImportInt(array $raw, string $key, int $min, int $max, string $code, array &$errors, int $line): int|false|null
	{
		$v = trim((string)($raw[$key] ?? ''));
		if ($v === '') {
			return null;
		}
		if (preg_match('/^-?\d+$/', $v) !== 1 || (int)$v < $min || (int)$v > $max) {
			$errors[] = ['line' => $line, 'code' => $code, 'message' => $key . ' out of range'];
			return false;
		}
		return (int)$v;
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
				$this->locking->acquireLock(CodeRules::CODES_LOCK, ILockingProvider::LOCK_EXCLUSIVE);
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
			$this->locking->releaseLock(CodeRules::CODES_LOCK, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
}
