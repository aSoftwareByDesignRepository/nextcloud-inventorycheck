<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Exception\PermissionDeniedException;
use OCP\IConfig;
use OCP\IGroupManager;
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
}
