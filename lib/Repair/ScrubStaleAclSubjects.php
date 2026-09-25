<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Scrub iv_loc_acl grants whose subject no longer exists. Before the
 * GroupDeletedListener existed, deleting a user/group left stale grant rows
 * that silently re-activated if the same uid/gid was recreated. User rows are
 * covered defensively too (older installs predate the user-delete purge);
 * device rows are scrubbed against iv_scan_devices.
 *
 * Idempotent: only deletes rows whose subject fails the existence check.
 */
namespace OCA\InventoryCheck\Repair;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\LowStockNotifyService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

final class ScrubStaleAclSubjects implements IRepairStep
{
	/** UID-typed JSON config lists whose entries must reference live users. */
	private const USER_LIST_KEYS = [
		AccessControlService::KEY_APP_ADMINS,
		AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS,
		AccessControlService::KEY_OFFICE_USER_IDS,
		LowStockNotifyService::KEY_NOTIFY_USER_IDS,
	];

	/** GID-typed JSON config lists whose entries must reference live groups. */
	private const GROUP_LIST_KEYS = [
		AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS,
		AccessControlService::KEY_OFFICE_GROUP_IDS,
		LowStockNotifyService::KEY_NOTIFY_GROUP_IDS,
	];

	/** Tables holding a location_id foreign reference (no DB-level FK). */
	private const LOCATION_REF_TABLES = ['iv_loc_acl', 'iv_loc_fav'];

	public function __construct(
		private readonly IDBConnection $connection,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly IConfig $config,
	) {
	}

	public function getName(): string
	{
		return 'Remove location ACL grants and config entries for deleted users, groups, devices, and locations';
	}

	public function run(IOutput $output): void
	{
		// Independent of iv_loc_acl: config lists and location refs get their
		// own table guards, and must still run when the ACL table is absent.
		$this->scrubConfigLists($output);
		$this->scrubDanglingLocationRefs($output);

		if (!$this->connection->tableExists('iv_loc_acl')) {
			return;
		}

		$deviceIds = [];
		if ($this->connection->tableExists('iv_scan_devices')) {
			$res = $this->connection->getQueryBuilder()
				->select('id')->from('iv_scan_devices')->executeQuery();
			while (($row = $res->fetch()) !== false) {
				$deviceIds[(int)$row['id']] = true;
			}
			$res->closeCursor();
		}

		$qb = $this->connection->getQueryBuilder();
		$qb->select('subject_type', 'subject_id')
			->from('iv_loc_acl')
			->groupBy('subject_type')
			->addGroupBy('subject_id');
		$res = $qb->executeQuery();

		$stale = [];
		while (($row = $res->fetch()) !== false) {
			$type = (string)$row['subject_type'];
			$id = (string)$row['subject_id'];
			$dead = match ($type) {
				'user' => !$this->userManager->userExists($id),
				'group' => !$this->groupManager->groupExists($id),
				'device' => !isset($deviceIds[(int)$id]),
				default => true,
			};
			if ($dead) {
				$stale[] = [$type, $id];
			}
		}
		$res->closeCursor();

		$removed = 0;
		foreach ($stale as [$type, $id]) {
			$del = $this->connection->getQueryBuilder();
			$del->delete('iv_loc_acl')
				->where($del->expr()->eq('subject_type', $del->createNamedParameter($type)))
				->andWhere($del->expr()->eq('subject_id', $del->createNamedParameter($id)));
			$removed += $del->executeStatement();
		}

		if ($removed > 0) {
			$output->info(sprintf('inventorycheck: removed %d stale location ACL grant(s).', $removed));
		}
	}

	/**
	 * JSON config lists (app admins, access allow-lists, office role, low-stock
	 * notify targets) hold bare uid/gid strings. A deleted subject that is
	 * later recreated would silently regain its grants — strip stale entries.
	 */
	private function scrubConfigLists(IOutput $output): void
	{
		$removed = 0;
		foreach (self::USER_LIST_KEYS as $key) {
			$removed += $this->scrubList($key, fn (string $id): bool => !$this->userManager->userExists($id));
		}
		foreach (self::GROUP_LIST_KEYS as $key) {
			$removed += $this->scrubList($key, fn (string $id): bool => !$this->groupManager->groupExists($id));
		}
		if ($removed > 0) {
			$output->info(sprintf('inventorycheck: removed %d stale subject(s) from app config lists.', $removed));
		}
	}

	/** @param callable(string): bool $isDead */
	private function scrubList(string $key, callable $isDead): int
	{
		$raw = $this->config->getAppValue(Application::APP_ID, $key, '');
		if ($raw === '') {
			return 0;
		}
		$ids = json_decode($raw, true);
		if (!is_array($ids)) {
			return 0;
		}
		$kept = array_values(array_filter(
			$ids,
			static fn ($id): bool => !is_string($id) || !$isDead($id),
		));
		$removed = count($ids) - count($kept);
		if ($removed > 0) {
			$this->config->setAppValue(Application::APP_ID, $key, json_encode($kept));
		}
		return $removed;
	}

	/**
	 * Location deletes purge their own ACL/favourite rows, but installs that
	 * predate that cleanup (or rows planted manually) can leave references to
	 * location ids that no longer exist. InnoDB id reuse would reattach those
	 * grants to an unrelated new location — delete dangling rows.
	 */
	private function scrubDanglingLocationRefs(IOutput $output): void
	{
		if (!$this->connection->tableExists('iv_locations')) {
			return;
		}

		$live = [];
		$res = $this->connection->getQueryBuilder()
			->select('id')->from('iv_locations')->executeQuery();
		while (($row = $res->fetch()) !== false) {
			$live[(int)$row['id']] = true;
		}
		$res->closeCursor();

		$removed = 0;
		foreach (self::LOCATION_REF_TABLES as $table) {
			if (!$this->connection->tableExists($table)) {
				continue;
			}
			$qb = $this->connection->getQueryBuilder();
			$qb->select('location_id')->from($table)->groupBy('location_id');
			$res = $qb->executeQuery();
			$dangling = [];
			while (($row = $res->fetch()) !== false) {
				$locId = (int)$row['location_id'];
				if (!isset($live[$locId])) {
					$dangling[] = $locId;
				}
			}
			$res->closeCursor();

			foreach ($dangling as $locId) {
				$del = $this->connection->getQueryBuilder();
				$del->delete($table)
					->where($del->expr()->eq('location_id', $del->createNamedParameter($locId)));
				$removed += $del->executeStatement();
			}
		}

		if ($removed > 0) {
			$output->info(sprintf('inventorycheck: removed %d row(s) referencing deleted location(s).', $removed));
		}
	}
}
