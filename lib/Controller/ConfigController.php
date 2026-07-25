<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
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
		]);
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
		// L0 only — L1 payloads that include appAdmins are ignored (no escalation).
		$appAdmins = null;
		if (array_key_exists('appAdmins', $p) && $this->access->isSystemAdmin($uid)) {
			$appAdmins = $this->validatedUserIds($p['appAdmins'], 'appAdmins');
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
	 * Accept JSON booleans and the appconfig-style 0/1 wire forms.
	 * Never use bare `(bool)$value` — in PHP `(bool)'0'` is true.
	 */
	private function parseBool(mixed $value, string $field): bool
	{
		if (is_bool($value)) {
			return $value;
		}
		if ($value === 1 || $value === '1') {
			return true;
		}
		if ($value === 0 || $value === '0') {
			return false;
		}
		throw new ValidationException('validation_failed', $field . ' must be a boolean.', [
			['field' => $field, 'code' => 'invalid_type'],
		]);
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
