<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Directory search backing the Settings pickers (allow/office/notify lists,
 * delegated app admins, per-location ACL grants, mobile seat assignment).
 *
 * Portfolio rule (planning/check-productivity-suite/ACCESS-AND-DIRECTORY-PICKERS.md
 * §1 "Never ask humans to type raw IDs"): the UI only ever offers search + pick.
 * This service never trusts its own output as authorization — every write path
 * still re-validates the committed id against IUserManager/IGroupManager
 * (ConfigController::validatedUserIds/validatedGroupIds, LicenseService::assignSeat,
 * LocationAclService::setForSubject/replaceAll).
 */
class DirectoryOptionsService
{
	private const MAX_LIMIT = 50;

	public function __construct(
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
	) {
	}

	/**
	 * @return list<array{id: string, displayName: string}>
	 */
	public function searchUsers(string $query, int $limit = 20): array
	{
		$query = trim($query);
		if (mb_strlen($query) < 2 || $limit < 1) {
			return [];
		}
		$limit = min(self::MAX_LIMIT, $limit);
		$byId = $this->userManager->search($query, $limit, 0);
		$byName = $this->userManager->searchDisplayName($query, $limit, 0);
		$merged = [];
		foreach (array_merge($byId, $byName) as $user) {
			if ($user === null) {
				continue;
			}
			$uid = trim((string)$user->getUID());
			if ($uid === '' || isset($merged[$uid])) {
				continue;
			}
			// Disabled accounts can still be granted access/office/notify roles
			// (an admin may re-enable them later); we only filter out empty uids.
			$displayName = trim((string)$user->getDisplayName());
			$merged[$uid] = [
				'id' => $uid,
				'displayName' => $displayName !== '' ? $displayName : $uid,
			];
			if (count($merged) >= $limit) {
				break;
			}
		}
		$out = array_values($merged);
		usort($out, static fn (array $a, array $b): int => strcasecmp($a['displayName'], $b['displayName']));
		return $out;
	}

	/**
	 * @return list<array{id: string, displayName: string}>
	 */
	public function searchGroups(string $query, int $limit = 20): array
	{
		$query = trim($query);
		if ($query === '' || $limit < 1) {
			return [];
		}
		$limit = min(self::MAX_LIMIT, $limit);
		$out = [];
		foreach ($this->groupManager->search($query, $limit) as $group) {
			if ($group === null) {
				continue;
			}
			$gid = trim((string)$group->getGID());
			if ($gid === '') {
				continue;
			}
			$displayName = trim((string)($group->getDisplayName() ?? ''));
			$out[] = [
				'id' => $gid,
				'displayName' => $displayName !== '' ? $displayName : $gid,
			];
		}
		usort($out, static fn (array $a, array $b): int => strcasecmp($a['displayName'], $b['displayName']));
		return $out;
	}
}
