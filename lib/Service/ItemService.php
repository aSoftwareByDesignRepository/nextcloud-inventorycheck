<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Db\CycleLineMapper;
use OCA\InventoryCheck\Db\Item;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\LocationMapper;
use OCA\InventoryCheck\Db\LotMapper;
use OCA\InventoryCheck\Db\UniqueViolation;
use OCA\InventoryCheck\Exception\ConflictException;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

class ItemService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly ItemMapper $items,
		private readonly BalanceMapper $balances,
		private readonly AccessControlService $access,
		private readonly Clock $clock,
		private readonly ILockingProvider $locking,
		private readonly LocationAclService $locationAcl,
		private readonly IConfig $config,
		private readonly CycleLineMapper $cycleLines,
		private readonly LocationMapper $locations,
		private readonly ?ItemPhotoService $photos = null,
		private readonly ?RestockConfig $restock = null,
		private readonly ?LotMapper $lots = null,
	) {
	}

	/** @return array{data: list<array<string, mixed>>, total: int, limit: int, offset: int} */
	public function list(string $actorUid, string $q, ?bool $active, bool $lowStock, int $limit, int $offset): array
	{
		$idFilter = null;
		if ($lowStock) {
			$idFilter = $this->lowStockItemIds($actorUid);
			if ($idFilter === []) {
				return ['data' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset];
			}
		}
		$result = $this->items->search($q, $active, $limit, $offset, $idFilter);
		return [
			'data' => array_map(
				fn (Item $i) => $this->stripConfidentialItemFields($i->toApi(), $actorUid),
				$result['data'],
			),
			'total' => $result['total'],
			'limit' => $limit,
			'offset' => $offset,
		];
	}

	/**
	 * S10 predicate over active items — ids currently below reorder level.
	 * Wave C3: sum only over locations the actor may see.
	 *
	 * @return list<int>
	 */
	private function lowStockItemIds(string $actorUid): array
	{
		$visible = $this->locationAcl->visibleLocationIds($actorUid);
		$sums = $this->balances->sumQtyByItem($visible);
		$ids = [];
		foreach ($this->items->iterateActive() as $item) {
			$total = $sums[(int)$item->getId()] ?? 0;
			if (LowStockQuery::isLowStock(true, $item->getReorderLevel(), $total)) {
				$ids[] = (int)$item->getId();
			}
		}
		return $ids;
	}

	/**
	 * Field users/scanning devices may read the shared item catalog, but must
	 * not see cost-ish properties.
	 *
	 * @param array<string, mixed> $item
	 * @return array<string, mixed>
	 */
	private function stripConfidentialItemFields(array $item, string $actorUid): array
	{
		// Centralized money/supplier gate (SPEC §15 — C-1/Q8 contract).
		return MoneyVisibility::itemForWeb($item, $this->access->isOffice($actorUid));
	}

	/** @return array<string, mixed> */
	public function get(string $actorUid, int $id): array
	{
		$item = $this->items->findById($id)->toApi();
		return $this->stripConfidentialItemFields($item, $actorUid);
	}

	/**
	 * S8 by-code with per-location balances (Wave C3: balances filtered to
	 * locations the actor may see — never leak hidden van stock via scan).
	 *
	 * @return array<string, mixed>
	 */
	public function byCode(string $actorUid, string $code): array
	{
		$api = $this->byCodeRaw($actorUid, $code);
		return $this->stripConfidentialItemFields($api, $actorUid);
	}

	/**
	 * Unstripped by-code for callers that apply their own caller-class gate
	 * (mobile boundary — MoneyVisibility::itemForMobile).
	 *
	 * @return array<string, mixed>
	 */
	public function byCodeRaw(string $actorUid, string $code): array
	{
		$code = CodeRules::trim($code);
		$item = $this->items->resolveByCode($code);
		if ($item === null) {
			throw new NotFoundException('code_not_found');
		}
		$api = $item->toApi();
		$visible = $this->locationAcl->visibleLocationIds($actorUid);
		$bal = $this->balances->search((int)$item->getId(), null, false, 200, 0, false, $visible);
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
		$trackMode = $this->parseTrackMode($input, 'none');
		$supplierNote = $this->parseSupplierNote($input);
		$lastPriceMinor = $this->parseLastPriceMinor($input);
		$targetStock = $this->parseTargetStock($input, $reorder);
		$defaultLocationId = $this->parseDefaultLocationId($input);
		$restock = $this->parseRestockFields($input, null);

		return $this->withCodesLock(function () use ($actorUid, $sku, $scan, $name, $uom, $desc, $reorder, $trackMode, $supplierNote, $lastPriceMinor, $targetStock, $defaultLocationId, $restock): array {
			if (CodeRules::conflictsWithOthers(null, $sku, $scan, $this->items->allCodePairs())) {
				throw new ConflictException('code_exists');
			}
			if (CodeRules::conflictsWithLocationCodes($sku, $scan, $this->locations->allCodes())) {
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
			$item->setTargetStock($targetStock);
			$item->setDefaultLocationId($defaultLocationId);
			$item->setActive(true);
			$item->setCreatedAt($now);
			$item->setUpdatedAt($now);
			$item->setCreatedBy($actorUid);
			$item->setTrackMode($trackMode);
			$item->setSupplierNote($supplierNote);
			$item->setLastPriceMinor($lastPriceMinor);
			$this->applyRestockFields($item, $restock);
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
		$photoName = null;
		$this->db->beginTransaction();
		try {
			// Exclusive lock conflicts with the shared lock held by any
			// in-flight movement, so the reference count is authoritative (S6).
			$item = $this->items->lockById($id, true);
			if ($this->items->countMovementsReferencing($id) > 0) {
				throw new ConflictException('item_has_movements');
			}
			if ($this->cycleLines->countOpenCampaignsForItem($id) > 0) {
				throw new ConflictException('item_in_open_stocktake');
			}
			$photoName = $item->getPhotoName();
			$this->items->delete($item);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		// Post-commit: the AppData blob must not outlive the item row.
		if ($photoName !== null && $photoName !== '') {
			$this->photos?->purgeFile($photoName);
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
				if ($reorder < 0 || $reorder > QtyScale::maxStorage($this->config)) {
					throw new ValidationException('validation_failed', '', [['field' => 'reorderLevel', 'code' => 'validation_failed']]);
				}
				$item->setReorderLevel($reorder);
			}
			if (array_key_exists('targetStock', $input) || array_key_exists('target_stock', $input)) {
				$item->setTargetStock($this->parseTargetStock($input, $item->getReorderLevel()));
			}
			if (array_key_exists('defaultLocationId', $input) || array_key_exists('default_location_id', $input)) {
				$item->setDefaultLocationId($this->parseDefaultLocationId($input));
			}
			if ($sku !== $item->getSku() || $scan !== $item->getScanCode()) {
				if (!CodeRules::isValidSku($sku) || !CodeRules::isValidScanCode($scan)) {
					$bad = [];
					if (!CodeRules::isValidSku($sku)) {
						$bad[] = ['field' => 'sku', 'code' => 'invalid_code_format'];
					}
					if (!CodeRules::isValidScanCode($scan)) {
						$bad[] = ['field' => 'scanCode', 'code' => 'invalid_code_format'];
					}
					throw new ValidationException('invalid_code_format', '', $bad);
				}
				if (CodeRules::conflictsWithOthers($id, $sku, $scan, $this->items->allCodePairs())) {
					throw new ConflictException('code_exists');
				}
				if (CodeRules::conflictsWithLocationCodes($sku, $scan, $this->locations->allCodes())) {
					throw new ConflictException('code_exists');
				}
				$item->setSku($sku);
				$item->setScanCode($scan);
			}
			if (array_key_exists('active', $input)) {
				$active = BoolParam::parse($input['active'], 'active');
				if (!$active && $item->getActive()) {
					if ($this->items->hasNonZeroBalance($id)) {
						throw new ConflictException('item_has_stock');
					}
					if ($this->cycleLines->countOpenCampaignsForItem($id) > 0) {
						throw new ConflictException('item_in_open_stocktake');
					}
				}
				$item->setActive($active);
			}
			if (array_key_exists('trackMode', $input) || array_key_exists('track_mode', $input)) {
				$nextMode = $this->parseTrackMode($input, $item->getTrackMode());
				$this->assertTrackModeChangeAllowed($id, $item->getTrackMode(), $nextMode);
				$item->setTrackMode($nextMode);
			}
			if (array_key_exists('supplierNote', $input) || array_key_exists('supplier_note', $input)) {
				$item->setSupplierNote($this->parseSupplierNote($input));
			}
			if (array_key_exists('lastPriceMinor', $input) || array_key_exists('last_price_minor', $input)) {
				$item->setLastPriceMinor($this->parseLastPriceMinor($input));
			}
			$this->applyRestockUpdate($item, $input);
			$item->setUpdatedAt($this->clock->now());
			$api = $this->items->update($item)->toApi();
			$this->db->commit();
			return $api;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw UniqueViolation::is($e) ? new ConflictException('code_exists') : $e;
		}
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private function parseSupplierNote(array $input): ?string
	{
		if (!array_key_exists('supplierNote', $input) && !array_key_exists('supplier_note', $input)) {
			return null;
		}
		$raw = $input['supplierNote'] ?? $input['supplier_note'];
		if ($raw === null) {
			return null;
		}
		$note = CodeRules::trim((string)$raw);
		if ($note === '') {
			return null;
		}
		if (mb_strlen($note) > 255) {
			throw new ValidationException('validation_failed', '', [['field' => 'supplierNote', 'code' => 'validation_failed']]);
		}
		return $note;
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private function parseLastPriceMinor(array $input): ?int
	{
		if (!array_key_exists('lastPriceMinor', $input) && !array_key_exists('last_price_minor', $input)) {
			return null;
		}
		$raw = $input['lastPriceMinor'] ?? $input['last_price_minor'];
		if ($raw === null || $raw === '') {
			return null;
		}
		if (!is_numeric($raw)) {
			throw new ValidationException('validation_failed', '', [['field' => 'lastPriceMinor', 'code' => 'validation_failed']]);
		}
		$value = (int)$raw;
		if ($value < 0 || $value > 100_000_000) {
			throw new ValidationException('validation_failed', '', [['field' => 'lastPriceMinor', 'code' => 'validation_failed']]);
		}
		return $value;
	}

	// ── Restock suite (F-01/F-03/F-09/F-11/F-19/F-27) ─────────────────────

	/**
	 * @param array<string, mixed> $input
	 * @return array{orderPackQty: ?int, orderPackPriceMinor: ?int, supplierName: ?string, supplierSku: ?string, supplierLeadDays: ?int, category: ?string, criticality: string, deliveryNotePath: ?string}
	 */
	private function parseRestockFields(array $input, ?Item $current): array
	{
		return [
			'orderPackQty' => $this->parseNullableInt($input, 'orderPackQty', 'order_pack_qty', 1, QtyScale::maxStorage($this->config)),
			'orderPackPriceMinor' => $this->parseNullableInt($input, 'orderPackPriceMinor', 'order_pack_price_minor', 0, RestockConfig::MAX_MONEY_MINOR),
			'supplierName' => $this->parseNullableString($input, 'supplierName', 'supplier_name', 200),
			'supplierSku' => $this->parseNullableString($input, 'supplierSku', 'supplier_sku', 200),
			'supplierLeadDays' => $this->parseNullableInt($input, 'supplierLeadDays', 'supplier_lead_days', 0, 365),
			'category' => $this->parseCategory($input, $current),
			'criticality' => $this->parseCriticality($input, $current?->getCriticality() ?? 'none'),
			'deliveryNotePath' => $this->parseNullableString($input, 'deliveryNotePath', 'delivery_note_path', 512),
		];
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private function parseNullableInt(array $input, string $camel, string $snake, int $min, int $max): ?int
	{
		if (!array_key_exists($camel, $input) && !array_key_exists($snake, $input)) {
			return null;
		}
		$raw = $input[$camel] ?? $input[$snake];
		if ($raw === null || $raw === '') {
			return null;
		}
		if (!is_int($raw) && !(is_string($raw) && preg_match('/^-?\d+$/', $raw) === 1) && !is_numeric($raw)) {
			throw new ValidationException('validation_failed', '', [['field' => $camel, 'code' => 'validation_failed']]);
		}
		$v = (int)$raw;
		if ($v < $min || $v > $max) {
			throw new ValidationException('validation_failed', '', [['field' => $camel, 'code' => 'validation_failed']]);
		}
		return $v;
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private function parseNullableString(array $input, string $camel, string $snake, int $max): ?string
	{
		if (!array_key_exists($camel, $input) && !array_key_exists($snake, $input)) {
			return null;
		}
		$raw = $input[$camel] ?? $input[$snake];
		if ($raw === null) {
			return null;
		}
		$v = CodeRules::trim((string)$raw);
		if ($v === '') {
			return null;
		}
		if (mb_strlen($v) > $max) {
			throw new ValidationException('validation_failed', '', [['field' => $camel, 'code' => 'validation_failed']]);
		}
		return $v;
	}

	/**
	 * F-09: constrained to category_list — but an unchanged unlisted value
	 * stays (admin removed it from the list after tagging).
	 *
	 * @param array<string, mixed> $input
	 */
	private function parseCategory(array $input, ?Item $current): ?string
	{
		$category = $this->parseNullableString($input, 'category', 'category', 100);
		if ($category === null) {
			return null;
		}
		if ($current !== null && $category === $current->getCategory()) {
			return $category;
		}
		if ($this->restock !== null && !$this->restock->categoryKnown($category)) {
			throw new ValidationException('validation_failed', '', [['field' => 'category', 'code' => 'unknown_category']]);
		}
		return $category;
	}

	/** @param array<string, mixed> $input */
	private function parseCriticality(array $input, string $default): string
	{
		if (!array_key_exists('criticality', $input)) {
			return $default;
		}
		$raw = CodeRules::trim((string)$input['criticality']);
		if ($raw === '') {
			$raw = 'none';
		}
		if (!in_array($raw, ['none', 'critical'], true)) {
			throw new ValidationException('validation_failed', '', [['field' => 'criticality', 'code' => 'validation_failed']]);
		}
		return $raw;
	}

	/**
	 * @param array{orderPackQty: ?int, orderPackPriceMinor: ?int, supplierName: ?string, supplierSku: ?string, supplierLeadDays: ?int, category: ?string, criticality: string, deliveryNotePath: ?string} $r
	 */
	private function applyRestockFields(Item $item, array $r): void
	{
		$item->setOrderPackQty($r['orderPackQty']);
		$item->setOrderPackPriceMinor($r['orderPackPriceMinor']);
		$item->setSupplierName($r['supplierName']);
		$item->setSupplierSku($r['supplierSku']);
		$item->setSupplierLeadDays($r['supplierLeadDays']);
		$item->setCategory($r['category']);
		$item->setCriticality($r['criticality']);
		$item->setDeliveryNotePath($r['deliveryNotePath']);
	}

	/**
	 * Partial-update semantics: keys absent → unchanged; present null → cleared.
	 *
	 * @param array<string, mixed> $input
	 */
	private function applyRestockUpdate(Item $item, array $input): void
	{
		$present = static fn (string $camel, string $snake): bool =>
			array_key_exists($camel, $input) || array_key_exists($snake, $input);
		if ($present('orderPackQty', 'order_pack_qty')) {
			$item->setOrderPackQty($this->parseNullableInt($input, 'orderPackQty', 'order_pack_qty', 1, QtyScale::maxStorage($this->config)));
		}
		if ($present('orderPackPriceMinor', 'order_pack_price_minor')) {
			$item->setOrderPackPriceMinor($this->parseNullableInt($input, 'orderPackPriceMinor', 'order_pack_price_minor', 0, RestockConfig::MAX_MONEY_MINOR));
		}
		if ($present('supplierName', 'supplier_name')) {
			$item->setSupplierName($this->parseNullableString($input, 'supplierName', 'supplier_name', 200));
		}
		if ($present('supplierSku', 'supplier_sku')) {
			$item->setSupplierSku($this->parseNullableString($input, 'supplierSku', 'supplier_sku', 200));
		}
		if ($present('supplierLeadDays', 'supplier_lead_days')) {
			$item->setSupplierLeadDays($this->parseNullableInt($input, 'supplierLeadDays', 'supplier_lead_days', 0, 365));
		}
		if ($present('category', 'category')) {
			$item->setCategory($this->parseCategory($input, $item));
		}
		if ($present('criticality', 'criticality')) {
			$item->setCriticality($this->parseCriticality($input, $item->getCriticality()));
		}
		if ($present('deliveryNotePath', 'delivery_note_path')) {
			$item->setDeliveryNotePath($this->parseNullableString($input, 'deliveryNotePath', 'delivery_note_path', 512));
		}
	}

	// ── On-order marker (F-04 / MF-02 / NN-09) ────────────────────────────

	/**
	 * Office+ "mark as ordered". Targeted column UPDATE under the exclusive
	 * item lock — serializes against entity updates and receive auto-clear.
	 */
	public function markOnOrder(string $actorUid, int $id, ?int $qty): void
	{
		$this->access->requireOffice($actorUid);
		$this->db->beginTransaction();
		try {
			$item = $this->items->lockById($id, true);
			if (!$item->getActive()) {
				throw new ValidationException('inactive_item');
			}
			$max = QtyScale::maxStorage($this->config);
			if ($qty === null) {
				$qty = $item->getOrderPackQty() !== null && $item->getOrderPackQty() > 0
					? (RestockEstimate::packs(max(1, $item->getReorderLevel()), $item->getOrderPackQty()) ?? 1) * $item->getOrderPackQty()
					: $item->getReorderLevel();
			}
			if ($qty < 1 || $qty > $max) {
				throw new ValidationException('validation_failed', '', [['field' => 'onOrderQty', 'code' => 'validation_failed']]);
			}
			$this->items->markOnOrder($id, $qty, $this->clock->now(), $actorUid);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/** Office+ manual clear — audit-logged by the controller. */
	public function clearOnOrder(string $actorUid, int $id): void
	{
		$this->access->requireOffice($actorUid);
		$this->db->beginTransaction();
		try {
			$this->items->lockById($id, true);
			$this->items->clearOnOrder($id);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Post-export bulk mark (§7.4) — one tx per item batch, exclusive lock
	 * each so a concurrent receive still serializes on the item row.
	 *
	 * @param list<array{id: int, qty: ?int}> $lines
	 */
	public function markOnOrderBulk(string $actorUid, array $lines): void
	{
		$this->access->requireOffice($actorUid);
		$max = QtyScale::maxStorage($this->config);
		$this->db->beginTransaction();
		try {
			foreach ($lines as $line) {
				$id = (int)($line['id'] ?? 0);
				$qty = isset($line['qty']) ? (int)$line['qty'] : null;
				$item = $this->items->lockById($id, true);
				if (!$item->getActive()) {
					throw new ValidationException('inactive_item');
				}
				if ($qty === null) {
					$qty = max(1, $item->getReorderLevel());
				}
				if ($qty < 1 || $qty > $max) {
					throw new ValidationException('validation_failed', '', [['field' => 'onOrderQty', 'code' => 'validation_failed']]);
				}
				$this->items->markOnOrder($id, $qty, $this->clock->now(), $actorUid);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	// ── Mobile seat create (F-18 / MF-03) ─────────────────────────────────

	/**
	 * Shelf-scan onboarding create. Any seat may call (gate lives in
	 * MobileController); field seats silently drop money/supplier inputs.
	 * Empty name allowed — item lands as an offline draft (AUTO-<code>)
	 * surfacing an "incomplete" badge on web until edited.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function createForSeat(string $actorUid, array $input, bool $isOffice): array
	{
		$scan = CodeRules::trim((string)($input['scanCode'] ?? $input['scan_code'] ?? ''));
		$sku = CodeRules::trim((string)($input['sku'] ?? ''));
		if ($scan === '' && $sku === '') {
			throw new ValidationException('invalid_code_format', '', [['field' => 'scanCode', 'code' => 'invalid_code_format']]);
		}
		if ($scan === '') {
			$scan = $sku;
		}
		if ($sku === '') {
			$sku = 'AUTO-' . strtoupper($scan);
		}
		$name = CodeRules::trim((string)($input['name'] ?? ''));
		if ($name === '' || mb_strlen($name) > 255) {
			throw new ValidationException('validation_failed', '', [['field' => 'name', 'code' => 'name_required']]);
		}
		$uom = CodeRules::trim((string)($input['uom'] ?? 'pcs'));
		if ($uom === '') {
			$uom = 'pcs';
		}
		$desc = isset($input['description']) ? CodeRules::trim((string)$input['description']) : null;
		if ($desc === '') {
			$desc = null;
		}
		$reorder = isset($input['reorderLevel']) ? (int)$input['reorderLevel'] : 0;
		if (!CodeRules::isValidSku($sku) || !CodeRules::isValidScanCode($scan)) {
			$bad = [];
			if (!CodeRules::isValidSku($sku)) {
				$bad[] = ['field' => 'sku', 'code' => 'invalid_code_format'];
			}
			if (!CodeRules::isValidScanCode($scan)) {
				$bad[] = ['field' => 'scanCode', 'code' => 'invalid_code_format'];
			}
			throw new ValidationException('invalid_code_format', '', $bad);
		}
		if ($reorder < 0 || $reorder > QtyScale::maxStorage($this->config)) {
			throw new ValidationException('validation_failed', '', [['field' => 'reorderLevel', 'code' => 'validation_failed']]);
		}
		if (mb_strlen($uom) < 1 || mb_strlen($uom) > 32) {
			throw new ValidationException('validation_failed', '', [['field' => 'uom', 'code' => 'validation_failed']]);
		}
		// Field seats cannot smuggle procurement fields through the seat path.
		if (!$isOffice) {
			$input['orderPackPriceMinor'] = $input['order_pack_price_minor'] = null;
			$input['lastPriceMinor'] = $input['last_price_minor'] = null;
			$input['supplierName'] = $input['supplier_name'] = null;
			$input['supplierSku'] = $input['supplier_sku'] = null;
			$input['supplierNote'] = $input['supplier_note'] = null;
			$input['supplierLeadDays'] = $input['supplier_lead_days'] = null;
		}
		$trackMode = $this->parseTrackMode($input, 'none');
		$restock = $isOffice ? $this->parseRestockFields($input, null) : [
			'orderPackQty' => $this->parseNullableInt($input, 'orderPackQty', 'order_pack_qty', 1, QtyScale::maxStorage($this->config)),
			'orderPackPriceMinor' => null,
			'supplierName' => null,
			'supplierSku' => null,
			'supplierLeadDays' => null,
			'category' => null,
			'criticality' => 'none',
			'deliveryNotePath' => null,
		];
		$supplierNote = $isOffice ? $this->parseSupplierNote($input) : null;
		$lastPriceMinor = $isOffice ? $this->parseLastPriceMinor($input) : null;

		return $this->withCodesLock(function () use (
			$actorUid, $sku, $scan, $name, $uom, $desc, $reorder, $trackMode, $supplierNote, $lastPriceMinor, $restock,
		): array {
			if (CodeRules::conflictsWithOthers(null, $sku, $scan, $this->items->allCodePairs())) {
				throw new ConflictException('code_exists');
			}
			if (CodeRules::conflictsWithLocationCodes($sku, $scan, $this->locations->allCodes())) {
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
			$item->setTrackMode($trackMode);
			$item->setSupplierNote($supplierNote);
			$item->setLastPriceMinor($lastPriceMinor);
			$this->applyRestockFields($item, $restock);
			try {
				return $this->items->insert($item)->toApi();
			} catch (\Throwable $e) {
				throw UniqueViolation::is($e) ? new ConflictException('code_exists') : $e;
			}
		});
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private function parseTrackMode(array $input, string $default): string
	{
		if (!array_key_exists('trackMode', $input) && !array_key_exists('track_mode', $input)) {
			return $default;
		}
		$raw = CodeRules::trim((string)($input['trackMode'] ?? $input['track_mode']));
		if ($raw === '') {
			$raw = 'none';
		}
		if (!CodeRules::isValidTrackMode($raw)) {
			throw new ValidationException('validation_failed', '', [['field' => 'trackMode', 'code' => 'validation_failed']]);
		}
		return $raw;
	}

	/**
	 * Upgrading trackMode (none→lot|serial, lot→serial) while anonymous stock
	 * exists lets capacity checks ignore pre-flip qty and corrupt the ledger.
	 * Downgrades and same-mode writes stay allowed; inventur mid-campaign flips
	 * require zeroing stock first (then close still detects track_mode_changed).
	 */
	private function assertTrackModeChangeAllowed(int $itemId, string $from, string $to): void
	{
		if ($from === $to) {
			return;
		}
		$rank = ['none' => 0, 'lot' => 1, 'serial' => 2];
		$fromRank = $rank[$from] ?? 0;
		$toRank = $rank[$to] ?? 0;
		if ($toRank <= $fromRank) {
			return;
		}
		if ($this->items->hasNonZeroBalance($itemId)) {
			throw new ConflictException('track_mode_requires_zero_stock');
		}
	}

	/**
	 * Wave D4: optional order-up-to qty (storage units). Null clears.
	 *
	 * @param array<string, mixed> $input
	 */
	private function parseTargetStock(array $input, int $reorderLevel): ?int
	{
		if (!array_key_exists('targetStock', $input) && !array_key_exists('target_stock', $input)) {
			return null;
		}
		$raw = $input['targetStock'] ?? $input['target_stock'];
		if ($raw === null || $raw === '') {
			return null;
		}
		if (!is_numeric($raw)) {
			throw new ValidationException('validation_failed', '', [['field' => 'targetStock', 'code' => 'validation_failed']]);
		}
		$value = (int)$raw;
		$max = QtyScale::maxStorage($this->config);
		if ($value < 0 || $value > $max) {
			throw new ValidationException('validation_failed', '', [['field' => 'targetStock', 'code' => 'validation_failed']]);
		}
		if ($value < $reorderLevel) {
			throw new ValidationException('validation_failed', '', [['field' => 'targetStock', 'code' => 'target_below_reorder']]);
		}
		return $value;
	}

	/**
	 * Wave D7: optional default putaway location. Null clears.
	 *
	 * @param array<string, mixed> $input
	 */
	private function parseDefaultLocationId(array $input): ?int
	{
		if (!array_key_exists('defaultLocationId', $input) && !array_key_exists('default_location_id', $input)) {
			return null;
		}
		$raw = $input['defaultLocationId'] ?? $input['default_location_id'];
		if ($raw === null || $raw === '' || (int)$raw === 0) {
			return null;
		}
		$id = (int)$raw;
		if ($id < 1) {
			throw new ValidationException('validation_failed', '', [['field' => 'defaultLocationId', 'code' => 'validation_failed']]);
		}
		try {
			$loc = $this->locations->findById($id);
		} catch (NotFoundException) {
			throw new ValidationException('validation_failed', '', [['field' => 'defaultLocationId', 'code' => 'unknown_location']]);
		}
		if (!$loc->getActive()) {
			throw new ValidationException('inactive_location', '', [['field' => 'defaultLocationId', 'code' => 'inactive_location']]);
		}
		return $id;
	}

	private function validateItemFields(string $sku, string $scan, string $name, string $uom, ?string $desc, int $reorder): void
	{
		if (!CodeRules::isValidSku($sku) || !CodeRules::isValidScanCode($scan)) {
			$bad = [];
			if (!CodeRules::isValidSku($sku)) {
				$bad[] = ['field' => 'sku', 'code' => 'invalid_code_format'];
			}
			if (!CodeRules::isValidScanCode($scan)) {
				$bad[] = ['field' => 'scanCode', 'code' => 'invalid_code_format'];
			}
			throw new ValidationException('invalid_code_format', '', $bad);
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
		if ($reorder < 0 || $reorder > QtyScale::maxStorage($this->config)) {
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
	/**
	 * F-14 preferred supplier — upserts iv_item_suppliers, marks the row
	 * preferred (demotes the rest). Office+ only; audited via caller.
	 */
	public function setPreferredSupplier(string $actorUid, int $itemId, int $supplierId, ?string $sku, ?int $priceMinor, ?int $packQty, ?int $leadDays): void
	{
		$this->access->requireOffice($actorUid);
		$this->items->findById($itemId);
		$qb = $this->db->getQueryBuilder();
		$qb->select("id")->from("iv_item_suppliers")
			->where($qb->expr()->eq("item_id", $qb->createNamedParameter($itemId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq("supplier_id", $qb->createNamedParameter($supplierId, \PDO::PARAM_INT)))
			->setMaxResults(1);
		$res = $qb->executeQuery();
		$row = $res->fetch();
		$res->closeCursor();
		$dem = $this->db->getQueryBuilder();
		$dem->update("iv_item_suppliers")->set("preferred", $dem->createNamedParameter(false, \PDO::PARAM_BOOL))
			->where($dem->expr()->eq("item_id", $dem->createNamedParameter($itemId, \PDO::PARAM_INT)));
		$dem->executeStatement();
		if ($row !== false) {
			$u = $this->db->getQueryBuilder();
			$u->update("iv_item_suppliers")
				->set("preferred", $u->createNamedParameter(true, \PDO::PARAM_BOOL))
				->set("supplier_sku", $u->createNamedParameter($sku))
				->set("price_minor", $u->createNamedParameter($priceMinor, \PDO::PARAM_INT))
				->set("pack_qty", $u->createNamedParameter($packQty, \PDO::PARAM_INT))
				->set("lead_days", $u->createNamedParameter($leadDays, \PDO::PARAM_INT))
				->where($u->expr()->eq("id", $u->createNamedParameter((int)$row["id"], \PDO::PARAM_INT)));
			$u->executeStatement();
		} else {
			$i = $this->db->getQueryBuilder();
			$i->insert("iv_item_suppliers")
				->setValue("item_id", $i->createNamedParameter($itemId, \PDO::PARAM_INT))
				->setValue("supplier_id", $i->createNamedParameter($supplierId, \PDO::PARAM_INT))
				->setValue("supplier_sku", $i->createNamedParameter($sku))
				->setValue("price_minor", $i->createNamedParameter($priceMinor, \PDO::PARAM_INT))
				->setValue("pack_qty", $i->createNamedParameter($packQty, \PDO::PARAM_INT))
				->setValue("lead_days", $i->createNamedParameter($leadDays, \PDO::PARAM_INT))
				->setValue("preferred", $i->createNamedParameter(true, \PDO::PARAM_BOOL));
			$i->executeStatement();
		}
	}
}
