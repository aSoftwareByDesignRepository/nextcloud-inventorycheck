<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Exception\AppAccessDeniedException;
use OCA\InventoryCheck\Exception\PermissionDeniedException;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * L0–L3 layer model (IMPLEMENTATION §2):
 *   L0 Nextcloud admin — always everything
 *   L1 app admin      — config list `app_admin_user_ids`
 *   L2 app access     — restriction toggle + allow-lists (or everyone)
 *   L3 office vs field — `office_user_ids` / `office_group_ids`
 */
class AccessControlService
{
	public const KEY_APP_ADMINS = 'app_admin_user_ids';
	public const KEY_ACCESS_RESTRICTION = 'access_restriction_enabled';
	public const KEY_ACCESS_ALLOWED_USER_IDS = 'access_allowed_user_ids';
	public const KEY_ACCESS_ALLOWED_GROUP_IDS = 'access_allowed_group_ids';
	public const KEY_OFFICE_USER_IDS = 'office_user_ids';
	public const KEY_OFFICE_GROUP_IDS = 'office_group_ids';
	public const KEY_ALLOW_NEGATIVE = 'allow_negative_stock';

	public const DENIAL_RESTRICTION = 'restriction';
	public const DENIAL_NOT_LOGGED_IN = 'not_logged_in';

	public function __construct(
		private readonly IConfig $config,
		private readonly IGroupManager $groupManager,
		private readonly IUserSession $userSession,
		private readonly IUserManager $userManager,
	) {
	}

	public function currentUserId(): string
	{
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new \RuntimeException('not_authenticated');
		}
		return $user->getUID();
	}

	public function isSystemAdmin(string $userId): bool
	{
		return $userId !== '' && $this->groupManager->isAdmin($userId);
	}

	public function isAppAdmin(string $userId): bool
	{
		if ($userId === '') {
			return false;
		}
		return $this->isSystemAdmin($userId)
			|| in_array($userId, $this->getJsonIdList(self::KEY_APP_ADMINS), true);
	}

	public function isAccessRestrictionEnabled(): bool
	{
		// Config stores '0'/'1'. Enabled ONLY when exactly '1' (default '0' = open).
		// Never invert this operator — mutation gauntlet kills !== flips.
		return $this->config->getAppValue(Application::APP_ID, self::KEY_ACCESS_RESTRICTION, '0') === '1';
	}

	public function canUseApp(string $userId): bool
	{
		if ($userId === '') {
			return false;
		}
		if ($this->isAppAdmin($userId)) {
			return true;
		}
		if ($this->isAccessRestrictionEnabled() && !$this->userMatchesAllowList($userId)) {
			return false;
		}
		return true;
	}

	public function denialReasonWhenCannotUseApp(string $userId): string
	{
		if ($userId === '') {
			return self::DENIAL_NOT_LOGGED_IN;
		}
		return self::DENIAL_RESTRICTION;
	}

	/**
	 * L3: office = app admin OR uid/group listed in office lists.
	 */
	public function isOffice(string $userId): bool
	{
		if ($userId === '') {
			return false;
		}
		if ($this->isAppAdmin($userId)) {
			return true;
		}
		if (in_array($userId, $this->getJsonIdList(self::KEY_OFFICE_USER_IDS), true)) {
			return true;
		}
		foreach ($this->getJsonIdList(self::KEY_OFFICE_GROUP_IDS) as $gid) {
			if ($this->groupManager->isInGroup($userId, $gid)) {
				return true;
			}
		}
		return false;
	}

	public function requireOffice(string $userId): void
	{
		if (!$this->isOffice($userId)) {
			throw new PermissionDeniedException();
		}
	}

	public function requireAppAdmin(string $userId): void
	{
		if (!$this->isAppAdmin($userId)) {
			throw new PermissionDeniedException();
		}
	}

	/**
	 * L2: actor may use the app at all (field, office, or admin).
	 * Scan-device actors ('device:N') skip canUseApp — MobileGateService
	 * already enforced license + device rank upstream.
	 */
	public function requireAppUser(string $userId): void
	{
		if (str_starts_with($userId, 'device:')) {
			return;
		}
		if (!$this->canUseApp($userId)) {
			throw new AppAccessDeniedException($this->denialReasonWhenCannotUseApp($userId));
		}
	}

	public function allowNegativeStock(): bool
	{
		return $this->config->getAppValue(Application::APP_ID, self::KEY_ALLOW_NEGATIVE, '0') === '1';
	}

	public function setAllowNegativeStock(bool $enabled): void
	{
		$this->config->setAppValue(Application::APP_ID, self::KEY_ALLOW_NEGATIVE, $enabled ? '1' : '0');
	}

	/**
	 * @return list<string>
	 */
	public function getJsonIdList(string $key): array
	{
		$raw = $this->config->getAppValue(Application::APP_ID, $key, '[]');
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return [];
		}
		$out = [];
		foreach ($decoded as $id) {
			$id = trim((string)$id);
			if ($id !== '') {
				$out[] = $id;
			}
		}
		return array_values(array_unique($out));
	}

	/**
	 * @param list<string> $ids
	 */
	public function setJsonIdList(string $key, array $ids): void
	{
		$clean = [];
		foreach ($ids as $id) {
			$id = trim((string)$id);
			if ($id !== '') {
				$clean[] = $id;
			}
		}
		$this->config->setAppValue(
			Application::APP_ID,
			$key,
			json_encode(array_values(array_unique($clean)), JSON_UNESCAPED_UNICODE),
		);
	}

	/**
	 * Portfolio §2.1 / GDPR user-delete: strip deleted UIDs from every
	 * authorization JSON list (app-admin, allow, office, low-stock notify).
	 * Seats / favourites / location ACL grants are purged by
	 * {@see \OCA\InventoryCheck\Listener\UserDeletedListener}.
	 * Idempotent — missing uid is a no-op.
	 */
	public function purgeUser(string $userId): void
	{
		if ($userId === '') {
			return;
		}
		foreach ([
			self::KEY_APP_ADMINS,
			self::KEY_ACCESS_ALLOWED_USER_IDS,
			self::KEY_OFFICE_USER_IDS,
			LowStockNotifyService::KEY_NOTIFY_USER_IDS,
		] as $key) {
			$ids = $this->getJsonIdList($key);
			$filtered = array_values(array_filter(
				$ids,
				static fn (string $id): bool => $id !== $userId,
			));
			if ($filtered !== $ids) {
				$this->setJsonIdList($key, $filtered);
			}
		}
	}

	/**
	 * Group-delete: strip the deleted GID from every authorization JSON list.
	 * A stale gid would silently re-grant access if the group is recreated.
	 * Idempotent — missing gid is a no-op.
	 */
	public function purgeGroup(string $groupId): void
	{
		if ($groupId === '') {
			return;
		}
		foreach ([
			self::KEY_ACCESS_ALLOWED_GROUP_IDS,
			self::KEY_OFFICE_GROUP_IDS,
			LowStockNotifyService::KEY_NOTIFY_GROUP_IDS,
		] as $key) {
			$ids = $this->getJsonIdList($key);
			$filtered = array_values(array_filter(
				$ids,
				static fn (string $id): bool => $id !== $groupId,
			));
			if ($filtered !== $ids) {
				$this->setJsonIdList($key, $filtered);
			}
		}
	}

	public function setAccessRestrictionEnabled(bool $enabled): void
	{
		$this->config->setAppValue(Application::APP_ID, self::KEY_ACCESS_RESTRICTION, $enabled ? '1' : '0');
	}

	private function userMatchesAllowList(string $userId): bool
	{
		if (in_array($userId, $this->getJsonIdList(self::KEY_ACCESS_ALLOWED_USER_IDS), true)) {
			return true;
		}
		foreach ($this->getJsonIdList(self::KEY_ACCESS_ALLOWED_GROUP_IDS) as $gid) {
			if ($this->groupManager->isInGroup($userId, $gid)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Current L1/L2 door policy as one array — the read half of
	 * {@see saveAppPolicy()} and the overlay base the config-transfer
	 * hooks decode mirrored keys onto.
	 *
	 * @return array{appAdminUserIds:list<string>,allowedUserIds:list<string>,allowedGroupIds:list<string>,accessRestrictionEnabled:bool}
	 */
	public function appPolicy(): array
	{
		return [
			'appAdminUserIds' => $this->getJsonIdList(self::KEY_APP_ADMINS),
			'allowedUserIds' => $this->getJsonIdList(self::KEY_ACCESS_ALLOWED_USER_IDS),
			'allowedGroupIds' => $this->getJsonIdList(self::KEY_ACCESS_ALLOWED_GROUP_IDS),
			'accessRestrictionEnabled' => $this->isAccessRestrictionEnabled(),
		];
	}

	/**
	 * Central atomic door-policy writer (config-transfer mirrored keys,
	 * spec NN-02): validate every field first, then persist all four keys —
	 * a restricted door with empty allowlists is rejected, never
	 * half-written. Same invariants {@see ConfigController::saveAccess()}
	 * enforces.
	 *
	 * @param array<string,mixed> $payload {
	 *   appAdminUserIds:list<string>, allowedUserIds:list<string>,
	 *   allowedGroupIds:list<string>, accessRestrictionEnabled:bool|string
	 * }
	 * @return array{appAdminUserIds:list<string>,allowedUserIds:list<string>,allowedGroupIds:list<string>,accessRestrictionEnabled:bool}
	 */
	public function saveAppPolicy(array $payload): array
	{
		foreach (['appAdminUserIds', 'allowedUserIds', 'allowedGroupIds', 'accessRestrictionEnabled'] as $field) {
			if (!array_key_exists($field, $payload)) {
				throw new \InvalidArgumentException('POLICY_FIELD_REQUIRED');
			}
		}
		foreach (['appAdminUserIds', 'allowedUserIds', 'allowedGroupIds'] as $listKey) {
			if (!is_array($payload[$listKey])) {
				throw new \InvalidArgumentException('POLICY_FIELD_REQUIRED');
			}
		}

		$appAdmins = $this->cleanIdList($payload['appAdminUserIds']);
		$allowedUsers = $this->cleanIdList($payload['allowedUserIds']);
		$allowedGroups = $this->cleanIdList($payload['allowedGroupIds']);
		$restriction = $payload['accessRestrictionEnabled'];
		$restriction = $restriction === true || $restriction === 1 || $restriction === '1';
		if (!is_bool($payload['accessRestrictionEnabled'])
			&& !in_array($payload['accessRestrictionEnabled'], [0, 1, '0', '1'], true)) {
			throw new \InvalidArgumentException('INVALID_ACCESS_RESTRICTION');
		}

		foreach ($appAdmins as $uid) {
			if (!$this->userManager->userExists($uid)) {
				throw new \InvalidArgumentException('INVALID_APP_ADMIN_USER');
			}
		}
		foreach ($allowedUsers as $uid) {
			if (!$this->userManager->userExists($uid)) {
				throw new \InvalidArgumentException('INVALID_ALLOWED_USER');
			}
		}
		foreach ($allowedGroups as $gid) {
			if (!$this->groupManager->groupExists($gid)) {
				throw new \InvalidArgumentException('INVALID_ALLOWED_GROUP');
			}
		}
		if ($restriction && $allowedUsers === [] && $allowedGroups === []) {
			throw new \InvalidArgumentException('ACCESS_LIST_REQUIRED');
		}

		$this->setJsonIdList(self::KEY_APP_ADMINS, $appAdmins);
		$this->setJsonIdList(self::KEY_ACCESS_ALLOWED_USER_IDS, $allowedUsers);
		$this->setJsonIdList(self::KEY_ACCESS_ALLOWED_GROUP_IDS, $allowedGroups);
		$this->setAccessRestrictionEnabled($restriction);

		return $this->appPolicy();
	}

	/**
	 * Validate + normalise ONE access-door appconfig value exactly the way
	 * {@see saveAppPolicy()} persists it — the shared normaliser the
	 * config-transfer engine calls (NN-08: import grants no validation
	 * bypass). Existence checks stay in saveAppPolicy/validateMirrored;
	 * this only enforces the stored shape.
	 *
	 * @throws \InvalidArgumentException on unknown keys or malformed values
	 */
	public function normalizeForWrite(string $key, string $value): string
	{
		return match ($key) {
			self::KEY_ACCESS_RESTRICTION => in_array($value, ['0', '1'], true)
				? $value
				: throw new \InvalidArgumentException('INVALID_ACCESS_RESTRICTION'),
			self::KEY_APP_ADMINS,
			self::KEY_ACCESS_ALLOWED_USER_IDS,
			self::KEY_ACCESS_ALLOWED_GROUP_IDS,
			self::KEY_OFFICE_USER_IDS,
			self::KEY_OFFICE_GROUP_IDS => self::normalizeIdListValue($value),
			default => throw new \InvalidArgumentException('UNKNOWN_SETTING'),
		};
	}

	/**
	 * Same canonical form {@see setJsonIdList()} stores: JSON list of
	 * non-empty unique string ids.
	 */
	public static function normalizeIdListValue(string $value): string
	{
		$list = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
		if (!is_array($list) || !array_is_list($list)) {
			throw new \InvalidArgumentException('INVALID_ID_LIST');
		}
		$clean = [];
		foreach ($list as $id) {
			if (is_string($id) || is_int($id)) {
				$id = trim((string)$id);
				if ($id !== '') {
					$clean[] = $id;
				}
			}
		}
		return json_encode(array_values(array_unique($clean)), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
	}

	/**
	 * @param array<mixed> $ids
	 * @return list<string>
	 */
	private function cleanIdList(array $ids): array
	{
		$clean = [];
		foreach ($ids as $id) {
			if (!is_string($id) && !is_int($id)) {
				throw new \InvalidArgumentException('INVALID_ID_LIST');
			}
			$id = trim((string)$id);
			if ($id !== '') {
				$clean[] = $id;
			}
		}
		return array_values(array_unique($clean));
	}
}
