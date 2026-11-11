<?php

declare(strict_types=1);

/**
 * InventoryCheck wiring for the shared transfer engine.
 *
 *  - requireTransferAdmin gates on the app's real door-admin role
 *    (AccessControlService::requireAppAdmin — L0 system admins + L1 app
 *    admins, IMPLEMENTATION §2).
 *  - validateValue/normalizeValue delegate to the SAME normalisers the
 *    settings surfaces use — AccessControlService::normalizeForWrite for
 *    the access-door + id-list keys, RestockConfig's parse* validators for
 *    the restock section, ProductLookupService::providerIds for the
 *    provider allowlist (validator parity, NN-08).
 *  - writeMirrored runs the access policy through
 *    AccessControlService::saveAppPolicy in ONE atomic call — never raw
 *    setAppValue (NN-02; restricted-door-requires-allowlist is cross-key).
 *  - createSafetySnapshot delegates to UpgradeBackupService for the
 *    mandatory pre-replace snapshot (FS-2).
 */

namespace OCA\InventoryCheck\ConfigTransfer;

use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\LowStockNotifyService;
use OCA\InventoryCheck\Service\ProductLookupService;
use OCA\InventoryCheck\Service\QtyScale;
use OCA\InventoryCheck\Service\RestockConfig;
use OCA\InventoryCheck\Service\UpgradeBackupService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

final class TransferHooks implements ConfigTransferHooks
{
	private const ACCESS_KEYS = [
		AccessControlService::KEY_ACCESS_RESTRICTION,
		AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS,
		AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS,
		AccessControlService::KEY_APP_ADMINS,
	];

	/** JSON id lists owned by AccessControlService outside the door policy. */
	private const ID_LIST_KEYS = [
		AccessControlService::KEY_OFFICE_USER_IDS,
		AccessControlService::KEY_OFFICE_GROUP_IDS,
		LowStockNotifyService::KEY_NOTIFY_USER_IDS,
		LowStockNotifyService::KEY_NOTIFY_GROUP_IDS,
	];

	/** Appconfig 0/1 toggles — the app's settings writes store '0'/'1'. */
	private const BOOL_KEYS = [
		AccessControlService::KEY_ALLOW_NEGATIVE,
		'location_reorder_hint_enabled',
		'maint_flange_enabled',
		'project_flange_enabled',
		'invoice_flange_enabled',
		'product_lookup_enabled',
		'product_lookup_autofire',
		'require_adjust_reason',
		'require_location_scan',
		'restock_digest_enabled',
	];

	public function __construct(
		private readonly AccessControlService $access,
		private readonly RestockConfig $restock,
		private readonly ProductLookupService $productLookup,
		private readonly UpgradeBackupService $backups,
		private readonly IDBConnection $db,
		private readonly IConfig $config,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}

	public function requireTransferAdmin(string $uid): void
	{
		$this->access->requireAppAdmin($uid);
	}

	public function validateValue(string $key, string $value): ?string
	{
		try {
			$this->normalize($key, $value);
			return null;
		} catch (\InvalidArgumentException $e) {
			return $e->getMessage() !== '' ? $e->getMessage() : 'INVALID_VALUE';
		} catch (ValidationException $e) {
			return $e->getMessage() !== '' ? $e->getMessage() : 'INVALID_VALUE';
		} catch (\JsonException) {
			return 'INVALID_JSON';
		} catch (\Throwable) {
			return 'INVALID_VALUE';
		}
	}

	public function normalizeValue(string $key, string $value): string
	{
		try {
			return $this->normalize($key, $value);
		} catch (\Throwable) {
			return $value; // validateValue already rejected bad input
		}
	}

	private function normalize(string $key, string $value): string
	{
		return match (true) {
			in_array($key, self::ACCESS_KEYS, true)
				=> $this->access->normalizeForWrite($key, $value),
			in_array($key, self::ID_LIST_KEYS, true)
				=> AccessControlService::normalizeIdListValue($value),
			in_array($key, self::BOOL_KEYS, true) => in_array($value, ['0', '1'], true)
				? $value
				: throw new \InvalidArgumentException('INVALID_BOOL'),
			$key === QtyScale::KEY => $this->normalizeQtyScale($value),
			$key === ProductLookupService::KEY_PROVIDERS => $this->normalizeProviders($value),
			$key === RestockConfig::KEY_BUDGETS => $this->normalizeBudgets($value),
			$key === RestockConfig::KEY_CATEGORY_LIST => $this->normalizeCategoryList($value),
			in_array($key, [
				RestockConfig::KEY_ON_ORDER_STALE_DAYS,
				RestockConfig::KEY_EXPIRY_WARN_DAYS,
				RestockConfig::KEY_VELOCITY_WINDOW_DAYS,
			], true) => (string)$this->restock->parseDays($value, $key),
			$key === RestockConfig::KEY_SUGGESTION_MODE => in_array($value, RestockConfig::SUGGESTION_MODES, true)
				? $value
				: throw new \InvalidArgumentException('INVALID_SUGGESTION_MODE'),
			in_array($key, [
				RestockConfig::KEY_APPROVAL_THRESHOLD,
				RestockConfig::KEY_EMERGENCY_BUDGET,
			], true) => $this->normalizeMinorAmount($value),
			default => strlen($value) <= 65536
				? $value
				: throw new \InvalidArgumentException('TOO_LONG'),
		};
	}

	/**
	 * qty_scale is enable-only (0 → 3 milli). A downgrade is always invalid
	 * (target rows are milli-scaled); a 0 → 3 upgrade is only coherent on
	 * an empty ledger — flipping scale under existing integer rows would
	 * silently misread every stored quantity.
	 */
	private function normalizeQtyScale(string $value): string
	{
		if (!in_array($value, [(string)QtyScale::SCALE_INT, (string)QtyScale::SCALE_MILLI], true)) {
			throw new \InvalidArgumentException('INVALID_QTY_SCALE');
		}
		$current = QtyScale::current($this->config);
		if ((int)$value === $current) {
			return $value;
		}
		if ((int)$value < $current) {
			throw new \InvalidArgumentException('QTY_SCALE_DOWNGRADE');
		}
		if ($this->hasMovements()) {
			throw new \InvalidArgumentException('QTY_SCALE_MISMATCH');
		}
		return $value;
	}

	private function hasMovements(): bool
	{
		if (!$this->db->tableExists('iv_movements')) {
			return false;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))->from('iv_movements');
		$result = $qb->executeQuery();
		$n = (int)$result->fetchOne();
		$result->closeCursor();
		return $n > 0;
	}

	/** Same closed-set rule ConfigController::validatedProviderIds enforces. */
	private function normalizeProviders(string $value): string
	{
		$list = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
		if (!is_array($list) || !array_is_list($list)) {
			throw new \InvalidArgumentException('INVALID_ID_LIST');
		}
		$known = $this->productLookup->providerIds();
		$clean = [];
		foreach ($list as $id) {
			if (!is_string($id) || $id === '') {
				throw new \InvalidArgumentException('INVALID_ID_LIST');
			}
			if (!in_array($id, $known, true)) {
				throw new \InvalidArgumentException('UNKNOWN_PROVIDER');
			}
			if (!in_array($id, $clean, true)) {
				$clean[] = $id;
			}
		}
		return json_encode(array_values($clean), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
	}

	/**
	 * Same shape + bounds {@see RestockConfig::parseBudgets()} enforces;
	 * stored form matches setBudgets (JSON object, unescaped unicode).
	 * Category-name checks are per-file — the file's own category_list is
	 * the authority, so validation here is shape/bounds only.
	 */
	private function normalizeBudgets(string $value): string
	{
		$decoded = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
		if (!is_array($decoded) || array_is_list($decoded)) {
			throw new \InvalidArgumentException('INVALID_BUDGET_MAP');
		}
		$clean = $this->restock->parseBudgets($decoded, []);
		return json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
	}

	private function normalizeCategoryList(string $value): string
	{
		$decoded = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
		if (!is_array($decoded) || !array_is_list($decoded)) {
			throw new \InvalidArgumentException('INVALID_CATEGORY_LIST');
		}
		$clean = $this->restock->parseCategoryList($decoded);
		return json_encode(array_values($clean), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
	}

	/** '' = unset (the settings API deletes the key); else digits ≤ cap. */
	private function normalizeMinorAmount(string $value): string
	{
		$raw = trim($value);
		if ($raw === '') {
			return '';
		}
		if (preg_match('/^\d+$/', $raw) !== 1 || (int)$raw > RestockConfig::MAX_SPEND_MINOR) {
			throw new \InvalidArgumentException('INVALID_AMOUNT');
		}
		return (string)(int)$raw;
	}

	public function validateMirrored(array $keyValues): ?string
	{
		$decoded = $this->decodePolicy($keyValues);
		if ($decoded === null) {
			return 'INVALID_POLICY_JSON';
		}
		[$admins, $users, $groups, $restricted] = $decoded;
		foreach ($admins as $uid) {
			if (!$this->userManager->userExists($uid)) {
				return 'INVALID_APP_ADMIN_USER';
			}
		}
		foreach ($users as $uid) {
			if (!$this->userManager->userExists($uid)) {
				return 'INVALID_ALLOWED_USER';
			}
		}
		foreach ($groups as $gid) {
			if (!$this->groupManager->groupExists($gid)) {
				return 'INVALID_ALLOWED_GROUP';
			}
		}
		if ($restricted && $users === [] && $groups === []) {
			return 'ACCESS_LIST_REQUIRED';
		}
		return null;
	}

	public function writeMirrored(array $keyValues): void
	{
		$decoded = $this->decodePolicy($keyValues);
		if ($decoded === null) {
			throw new \InvalidArgumentException('INVALID_POLICY_JSON');
		}
		[$admins, $users, $groups, $restricted] = $decoded;
		// One atomic policy write through the app's central writer — the
		// payload carries all four fields, so a partial transfer file never
		// wipes the sections it does not mention (decodePolicy overlays).
		$this->access->saveAppPolicy([
			'appAdminUserIds' => $admins,
			'allowedUserIds' => $users,
			'allowedGroupIds' => $groups,
			'accessRestrictionEnabled' => $restricted,
		]);
	}

	/**
	 * Overlay the transferred mirrored keys onto the current policy, so a
	 * file that carries only a subset never blanks the rest.
	 *
	 * @param array<string,string> $kv
	 * @return array{0:list<string>,1:list<string>,2:list<string>,3:bool}|null
	 */
	private function decodePolicy(array $kv): ?array
	{
		$current = $this->access->appPolicy();
		$admins = $current['appAdminUserIds'];
		$users = $current['allowedUserIds'];
		$groups = $current['allowedGroupIds'];
		$restricted = $current['accessRestrictionEnabled'];
		if (isset($kv[AccessControlService::KEY_APP_ADMINS])) {
			$admins = json_decode($kv[AccessControlService::KEY_APP_ADMINS], true);
			if (!is_array($admins)) {
				return null;
			}
		}
		if (isset($kv[AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS])) {
			$users = json_decode($kv[AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS], true);
			if (!is_array($users)) {
				return null;
			}
		}
		if (isset($kv[AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS])) {
			$groups = json_decode($kv[AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS], true);
			if (!is_array($groups)) {
				return null;
			}
		}
		if (isset($kv[AccessControlService::KEY_ACCESS_RESTRICTION])) {
			$restricted = $kv[AccessControlService::KEY_ACCESS_RESTRICTION] === '1';
		}
		return [
			array_values(array_map('strval', $admins)),
			array_values(array_map('strval', $users)),
			array_values(array_map('strval', $groups)),
			(bool)$restricted,
		];
	}

	public function securityRelevantKeys(): array
	{
		return [
			'access_restriction_enabled',
			'access_allowed_user_ids',
			'access_allowed_group_ids',
			'app_admin_user_ids',
			'office_user_ids',
			'office_group_ids',
			'allow_negative_stock',
			'maint_flange_enabled',
			'project_flange_enabled',
			'invoice_flange_enabled',
			'product_lookup_enabled',
			'order_approval_threshold_minor',
			'restock_emergency_budget_minor',
		];
	}

	public function createSafetySnapshot(string $reason): ?string
	{
		try {
			$result = $this->backups->createSnapshot($reason);
			return (string)($result['id'] ?? '');
		} catch (\Throwable) {
			// No data to back up yet (fresh instance) — not an error, but no
			// snapshot id either; the apply must not be silently unprotected
			// when tables DO exist, so rethrow real failures on non-empty DBs.
			if ($this->backups->hasDataToBackup()) {
				throw new \RuntimeException('TRANSFER_SNAPSHOT_FAILED');
			}
			return null;
		}
	}

	public function settingsSectionForKey(string $key): ?string
	{
		return match (true) {
			in_array($key, self::ACCESS_KEYS, true) => 'access',
			in_array($key, [
				AccessControlService::KEY_OFFICE_USER_IDS,
				AccessControlService::KEY_OFFICE_GROUP_IDS,
				AccessControlService::KEY_ALLOW_NEGATIVE,
			], true) => 'office',
			in_array($key, [
				LowStockNotifyService::KEY_NOTIFY_USER_IDS,
				LowStockNotifyService::KEY_NOTIFY_GROUP_IDS,
				'location_reorder_hint_enabled',
			], true) => 'notifications',
			$key === QtyScale::KEY => 'quantities',
			str_starts_with($key, 'location_acl_') => 'location-access',
			in_array($key, [
				'maint_flange_enabled',
				'project_flange_enabled',
				'invoice_flange_enabled',
				'flange_default_location_id',
			], true) => 'connections',
			str_starts_with($key, 'product_lookup_') => 'product-lookup',
			in_array($key, ['require_adjust_reason', 'require_location_scan'], true) => 'policies',
			default => 'restock',
		};
	}

	public function auditEvent(string $action, string $actor, array $details): void
	{
		$this->logger->info('inventorycheck: config transfer ' . $action, [
			'app' => 'inventorycheck',
			'actor' => $actor === '' ? 'cli' : $actor,
			'details' => $details,
		]);
	}
}
