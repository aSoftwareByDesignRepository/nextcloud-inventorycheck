<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\BoolParam;
use OCA\InventoryCheck\Service\LocationAclService;
use OCA\InventoryCheck\Service\LocationScanPolicy;
use OCA\InventoryCheck\Service\LowStockNotifyService;
use OCA\InventoryCheck\Service\LowStockService;
use OCA\InventoryCheck\Service\QtyScale;
use OCA\InventoryCheck\Service\QtyScaleService;
use OCA\InventoryCheck\Service\ReasonCodes;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;

/**
 * Access / office policy (P6). Unknown user/group ids → 422 so typos never
 * silently lock people out when restriction is enabled (sibling MN pattern).
 *
 * Writes are validate-then-commit: every present list is checked before any
 * appconfig key is mutated, so a bad group cannot leave a half-applied user list.
 */
class ConfigController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly AccessControlService $access,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly LowStockService $lowStock,
		private readonly QtyScaleService $qtyScaleService,
		private readonly LocationAclService $locationAcl,
		private readonly IConfig $config,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$isAppAdmin = $this->access->isAppAdmin($uid);
		$isSystemAdmin = $this->access->isSystemAdmin($uid);
		return new JSONResponse([
			'isAppAdmin' => $isAppAdmin,
			'isSystemAdmin' => $isSystemAdmin,
			'isOffice' => $this->access->isOffice($uid),
			'allowNegativeStock' => $this->access->allowNegativeStock(),
			'accessRestrictionEnabled' => $this->access->isAccessRestrictionEnabled(),
			// L1 sees the list (IMPLEMENTATION §2.1) but only L0 may rewrite it (saveAccess).
			'appAdmins' => $isAppAdmin
				? $this->access->getJsonIdList(AccessControlService::KEY_APP_ADMINS)
				: [],
			'allowedUsers' => $isAppAdmin
				? $this->access->getJsonIdList(AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS)
				: [],
			'allowedGroups' => $isAppAdmin
				? $this->access->getJsonIdList(AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS)
				: [],
			'officeUsers' => $isAppAdmin
				? $this->access->getJsonIdList(AccessControlService::KEY_OFFICE_USER_IDS)
				: [],
			'officeGroups' => $isAppAdmin
				? $this->access->getJsonIdList(AccessControlService::KEY_OFFICE_GROUP_IDS)
				: [],
			'lowStockNotifyUsers' => $isAppAdmin
				? $this->access->getJsonIdList(LowStockNotifyService::KEY_NOTIFY_USER_IDS)
				: [],
			'lowStockNotifyGroups' => $isAppAdmin
				? $this->access->getJsonIdList(LowStockNotifyService::KEY_NOTIFY_GROUP_IDS)
				: [],
			'locationReorderHintEnabled' => $this->lowStock->isPerLocationHintEnabled(),
			'qtyScale' => QtyScale::current($this->config),
			'locationAclEnabled' => $this->locationAcl->isEnabled(),
			'requireAdjustReason' => ReasonCodes::isRequired($this->config),
			'requireLocationScan' => LocationScanPolicy::isRequired($this->config),
		]);
	}

	/**
	 * C1: one-way opt-in to fractional (3-decimal) quantities. Enable-only —
	 * there is no disable endpoint, the rescale is irreversible.
	 */
	#[NoAdminRequired]
	public function saveFractional(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireAppAdmin($uid);
		$result = $this->qtyScaleService->enableFractional();
		return new JSONResponse(array_merge($result, ['qtyScale' => QtyScale::current($this->config)]));
	}

	/** Wave D3/D8 policies */
	#[NoAdminRequired]
	public function saveWaveD(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireAppAdmin($uid);
		$p = $this->request->getParams();
		if (array_key_exists('requireAdjustReason', $p)) {
			ReasonCodes::setRequired($this->config, $this->parseBool($p['requireAdjustReason'], 'requireAdjustReason'));
		}
		if (array_key_exists('requireLocationScan', $p)) {
			LocationScanPolicy::setRequired($this->config, $this->parseBool($p['requireLocationScan'], 'requireLocationScan'));
		}
		return $this->index();
	}

	/** Wave D3 catalog — any app user (P2) */
	#[NoAdminRequired]
	public function reasonCodes(): JSONResponse
	{
		return new JSONResponse(['data' => ReasonCodes::catalog()]);
	}

	/**
	 * C3: per-location ACL. App-admin only; GET lists every assignment,
	 * PUT replaces the full assignment for one subject (validate-then-commit).
	 */
	#[NoAdminRequired]
	public function locationAcl(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireAppAdmin($uid);
		return new JSONResponse([
			'enabled' => $this->locationAcl->isEnabled(),
			'devicesStrict' => $this->locationAcl->isDevicesStrict(),
			'assignments' => $this->locationAcl->listAll(),
		]);
	}

	#[NoAdminRequired]
	public function saveLocationAcl(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireAppAdmin($uid);
		$p = $this->request->getParams();

		// Phase 1 — parse/validate only (no writes). Writing `enabled` before a
		// failing assignment replace left ACL half-applied; commit enabled last.
		$enabled = array_key_exists('enabled', $p)
			? $this->parseBool($p['enabled'], 'enabled')
			: null;
		$devicesStrict = array_key_exists('devicesStrict', $p)
			? $this->parseBool($p['devicesStrict'], 'devicesStrict')
			: null;

		$assignments = null;
		$subjectType = null;
		$subjectId = null;
		$locationIds = null;
		if (array_key_exists('assignments', $p)) {
			if (!is_array($p['assignments'])) {
				throw new ValidationException('validation_failed', '', [
					['field' => 'assignments', 'code' => 'invalid_type'],
				]);
			}
			/** @var list<array<string, mixed>> $assignments */
			$assignments = $p['assignments'];
		} elseif (array_key_exists('subjectType', $p) || array_key_exists('subjectId', $p) || array_key_exists('locationIds', $p)) {
			$subjectType = is_string($p['subjectType'] ?? null) ? $p['subjectType'] : '';
			$subjectId = is_string($p['subjectId'] ?? null) ? trim($p['subjectId']) : '';
			$locationIds = is_array($p['locationIds'] ?? null) ? $p['locationIds'] : [];
		}

		// Phase 2 — assignments first, then flags (fail closed on partial apply).
		if ($assignments !== null) {
			$this->locationAcl->replaceAll($uid, $assignments);
		} elseif ($subjectId !== null && $subjectId !== '') {
			$this->locationAcl->setForSubject((string)$subjectType, $subjectId, $locationIds ?? []);
		}
		// Apply devicesStrict before enabled: enabling both in one save must not
		// briefly leave ACL on while strict is still off (unbound scanners org-wide).
		if ($devicesStrict !== null) {
			$this->locationAcl->setDevicesStrict($devicesStrict);
		}
		if ($enabled !== null) {
			$this->locationAcl->setEnabled($enabled);
		}

		return $this->locationAcl();
	}

	#[NoAdminRequired]
	public function saveAccess(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireAppAdmin($uid);
		$p = $this->request->getParams();

		// Phase 1 — validate everything that will be written (no side effects).
		$restriction = array_key_exists('accessRestrictionEnabled', $p)
			? $this->parseBool($p['accessRestrictionEnabled'], 'accessRestrictionEnabled')
			: null;
		$allowedUsers = array_key_exists('allowedUsers', $p)
			? $this->validatedUserIds($p['allowedUsers'], 'allowedUsers')
			: null;
		$allowedGroups = array_key_exists('allowedGroups', $p)
			? $this->validatedGroupIds($p['allowedGroups'], 'allowedGroups')
			: null;
		// Only Nextcloud system admins (L0) may rewrite the Dedicated App Admin list
		// (SPEC §ACL / portfolio privilege boundary). L1 sees the list but cannot escalate.
		$appAdmins = null;
		if (array_key_exists('appAdmins', $p) && $this->access->isSystemAdmin($uid)) {
			$appAdmins = $this->validatedUserIds($p['appAdmins'], 'appAdmins');
		}

		// Fail closed: restriction with empty allowlists locks every non-admin out.
		$effectiveRestriction = $restriction ?? $this->access->isAccessRestrictionEnabled();
		$effectiveUsers = $allowedUsers ?? $this->access->getJsonIdList(AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS);
		$effectiveGroups = $allowedGroups ?? $this->access->getJsonIdList(AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS);
		if ($effectiveRestriction && $effectiveUsers === [] && $effectiveGroups === []) {
			throw new ValidationException(
				'access_allowlist_required',
				'Access restriction requires at least one allowed user or group.',
				[['field' => 'allowedUsers', 'code' => 'access_allowlist_required']],
			);
		}

		// Phase 2 — commit only after all validations succeeded.
		if ($restriction !== null) {
			$this->access->setAccessRestrictionEnabled($restriction);
		}
		if ($allowedUsers !== null) {
			$this->access->setJsonIdList(AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS, $allowedUsers);
		}
		if ($allowedGroups !== null) {
			$this->access->setJsonIdList(AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS, $allowedGroups);
		}
		if ($appAdmins !== null) {
			$this->access->setJsonIdList(AccessControlService::KEY_APP_ADMINS, $appAdmins);
		}

		return $this->index();
	}

	#[NoAdminRequired]
	public function saveOffice(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireAppAdmin($uid);
		$p = $this->request->getParams();

		$officeUsers = array_key_exists('officeUsers', $p)
			? $this->validatedUserIds($p['officeUsers'], 'officeUsers')
			: null;
		$officeGroups = array_key_exists('officeGroups', $p)
			? $this->validatedGroupIds($p['officeGroups'], 'officeGroups')
			: null;
		$allowNegative = array_key_exists('allowNegativeStock', $p)
			? $this->parseBool($p['allowNegativeStock'], 'allowNegativeStock')
			: null;

		if ($officeUsers !== null) {
			$this->access->setJsonIdList(AccessControlService::KEY_OFFICE_USER_IDS, $officeUsers);
		}
		if ($officeGroups !== null) {
			$this->access->setJsonIdList(AccessControlService::KEY_OFFICE_GROUP_IDS, $officeGroups);
		}
		if ($allowNegative !== null) {
			$this->access->setAllowNegativeStock($allowNegative);
		}

		return $this->index();
	}

	/**
	 * Wave A3 / B3: low-stock notify recipients and the per-location reorder
	 * hint toggle. App-admin only, same validate-then-commit shape as
	 * {@see saveAccess()} / {@see saveOffice()}.
	 */
	#[NoAdminRequired]
	public function saveNotify(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireAppAdmin($uid);
		$p = $this->request->getParams();

		$notifyUsers = array_key_exists('lowStockNotifyUsers', $p)
			? $this->validatedUserIds($p['lowStockNotifyUsers'], 'lowStockNotifyUsers')
			: null;
		$notifyGroups = array_key_exists('lowStockNotifyGroups', $p)
			? $this->validatedGroupIds($p['lowStockNotifyGroups'], 'lowStockNotifyGroups')
			: null;
		$reorderHint = array_key_exists('locationReorderHintEnabled', $p)
			? $this->parseBool($p['locationReorderHintEnabled'], 'locationReorderHintEnabled')
			: null;

		if ($notifyUsers !== null) {
			$this->access->setJsonIdList(LowStockNotifyService::KEY_NOTIFY_USER_IDS, $notifyUsers);
		}
		if ($notifyGroups !== null) {
			$this->access->setJsonIdList(LowStockNotifyService::KEY_NOTIFY_GROUP_IDS, $notifyGroups);
		}
		if ($reorderHint !== null) {
			$this->lowStock->setPerLocationHintEnabled($reorderHint);
		}

		return $this->index();
	}

	/**
	 * Accept JSON booleans and the appconfig-style 0/1 wire forms.
	 * Never use bare `(bool)$value` — in PHP `(bool)'false'` is true.
	 */
	private function parseBool(mixed $value, string $field): bool
	{
		return BoolParam::parse($value, $field);
	}

	/**
	 * @return list<string>
	 */
	private function validatedUserIds(mixed $value, string $field): array
	{
		$ids = $this->stringList($value, $field);
		foreach ($ids as $userId) {
			if (!$this->userManager->userExists($userId)) {
				throw new ValidationException('unknown_user', 'Unknown user: ' . $userId, [
					['field' => $field, 'code' => 'unknown_user'],
				]);
			}
		}
		return $ids;
	}

	/**
	 * @return list<string>
	 */
	private function validatedGroupIds(mixed $value, string $field): array
	{
		$ids = $this->stringList($value, $field);
		foreach ($ids as $gid) {
			if (!$this->groupManager->groupExists($gid)) {
				throw new ValidationException('unknown_group', 'Unknown group: ' . $gid, [
					['field' => $field, 'code' => 'unknown_group'],
				]);
			}
		}
		return $ids;
	}

	/**
	 * @return list<string>
	 */
	private function stringList(mixed $value, string $field): array
	{
		if (!is_array($value)) {
			throw new ValidationException('validation_failed', $field . ' must be an array of ids.', [
				['field' => $field, 'code' => 'invalid_type'],
			]);
		}
		$out = [];
		foreach ($value as $id) {
			if (!is_string($id) && !is_int($id)) {
				throw new ValidationException('validation_failed', $field . ' must contain strings only.', [
					['field' => $field, 'code' => 'invalid_type'],
				]);
			}
			$id = trim((string)$id);
			if ($id !== '') {
				$out[] = $id;
			}
		}
		return array_values(array_unique($out));
	}
}
