<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Db\LocationMapper;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Wave C3: optional per-location ACL for field users.
 *
 * When disabled (default), all canUseApp users see every location.
 * When enabled, office/app-admin/system-admin still see all; field users only
 * see locations explicitly granted to their uid or groups. Zero grants → none.
 */
class LocationAclService
{
	public const KEY_ENABLED = 'location_acl_enabled';
	public const TYPE_USER = 'user';
	public const TYPE_GROUP = 'group';

	public function __construct(
		private readonly IDBConnection $db,
		private readonly IConfig $config,
		private readonly AccessControlService $access,
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
		private readonly LocationMapper $locations,
	) {
	}

	public function isEnabled(): bool
	{
		return $this->config->getAppValue(Application::APP_ID, self::KEY_ENABLED, '0') === '1';
	}

	public function setEnabled(bool $enabled): void
	{
		$this->config->setAppValue(Application::APP_ID, self::KEY_ENABLED, $enabled ? '1' : '0');
	}

	/**
	 * null = unrestricted (caller may see all locations).
	 *
	 * Device actors (`device:…`) are unrestricted: Wave C3 ACL is a web-field
	 * control. Scan devices are physically location-scoped; treating them as
	 * field users with zero grants would make every scan 404 while reads still
	 * showed all locations (asymmetric IDOR / dead scanners).
	 *
	 * @return list<int>|null
	 */
	public function visibleLocationIds(string $uid): ?array
	{
		if ($uid === '' || str_starts_with($uid, 'device:') || !$this->isEnabled()) {
			return null;
		}
		if ($this->access->isOffice($uid)) {
			return null;
		}
		$ids = [];
		$qb = $this->db->getQueryBuilder();
		$qb->select('location_id')->from('iv_loc_acl')
			->where($qb->expr()->eq('subject_type', $qb->createNamedParameter(self::TYPE_USER)))
			->andWhere($qb->expr()->eq('subject_id', $qb->createNamedParameter($uid)));
		$res = $qb->executeQuery();
		while (($row = $res->fetch()) !== false) {
			$ids[] = (int)$row['location_id'];
		}
		$res->closeCursor();

		$gids = [];
		$gQb = $this->db->getQueryBuilder();
		$gQb->selectDistinct('subject_id')->from('iv_loc_acl')
			->where($gQb->expr()->eq('subject_type', $gQb->createNamedParameter(self::TYPE_GROUP)));
		$gRes = $gQb->executeQuery();
		while (($grow = $gRes->fetch()) !== false) {
			$gid = (string)$grow['subject_id'];
			if ($gid !== '' && $this->groupManager->isInGroup($uid, $gid)) {
				$gids[] = $gid;
			}
		}
		$gRes->closeCursor();
		if ($gids !== []) {
			$qb2 = $this->db->getQueryBuilder();
			$qb2->select('location_id')->from('iv_loc_acl')
				->where($qb2->expr()->eq('subject_type', $qb2->createNamedParameter(self::TYPE_GROUP)))
				->andWhere($qb2->expr()->in('subject_id', $qb2->createNamedParameter($gids, IQueryBuilder::PARAM_STR_ARRAY)));
			$r2 = $qb2->executeQuery();
			while (($row = $r2->fetch()) !== false) {
				$ids[] = (int)$row['location_id'];
			}
			$r2->closeCursor();
		}
		return array_values(array_unique($ids));
	}

	public function canAccessLocation(string $uid, int $locationId): bool
	{
		$visible = $this->visibleLocationIds($uid);
		if ($visible === null) {
			return true;
		}
		return in_array($locationId, $visible, true);
	}

	public function assertCanAccess(string $uid, int $locationId): void
	{
		if (!$this->canAccessLocation($uid, $locationId)) {
			throw new NotFoundException('unknown_location');
		}
	}

	/**
	 * Replace every location grant for one subject (validate-then-commit).
	 * Empty $locationIds clears that subject's grants.
	 *
	 * @param list<mixed> $locationIds
	 */
	public function setForSubject(string $subjectType, string $subjectId, array $locationIds): void
	{
		$type = strtolower(trim($subjectType));
		$id = trim($subjectId);
		if (!in_array($type, [self::TYPE_USER, self::TYPE_GROUP], true) || $id === '') {
			throw new ValidationException('validation_failed', '', [
				['field' => 'subjectType', 'code' => 'validation_failed'],
			]);
		}
		if ($type === self::TYPE_USER && !$this->userManager->userExists($id)) {
			throw new ValidationException('unknown_user', 'Unknown user: ' . $id, [
				['field' => 'subjectId', 'code' => 'unknown_user'],
			]);
		}
		if ($type === self::TYPE_GROUP && !$this->groupManager->groupExists($id)) {
			throw new ValidationException('unknown_group', 'Unknown group: ' . $id, [
				['field' => 'subjectId', 'code' => 'unknown_group'],
			]);
		}
		$clean = [];
		foreach ($locationIds as $locId) {
			$n = (int)$locId;
			if ($n <= 0) {
				throw new ValidationException('validation_failed', '', [
					['field' => 'locationIds', 'code' => 'validation_failed'],
				]);
			}
			// Existence check — throws NotFoundException for phantom ids.
			$this->locations->findById($n);
			$clean[] = $n;
		}
		$clean = array_values(array_unique($clean));

		$this->db->beginTransaction();
		try {
			$del = $this->db->getQueryBuilder();
			$del->delete('iv_loc_acl')
				->where($del->expr()->eq('subject_type', $del->createNamedParameter($type)))
				->andWhere($del->expr()->eq('subject_id', $del->createNamedParameter($id)))
				->executeStatement();
			foreach ($clean as $locId) {
				$ins = $this->db->getQueryBuilder();
				$ins->insert('iv_loc_acl')->values([
					'subject_type' => $ins->createNamedParameter($type),
					'subject_id' => $ins->createNamedParameter($id),
					'location_id' => $ins->createNamedParameter($locId, IQueryBuilder::PARAM_INT),
				])->executeStatement();
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
	}

	/**
	 * @return list<array{subjectType: string, subjectId: string, locationId: int}>
	 */
	public function listAll(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('iv_loc_acl')
			->orderBy('subject_type', 'ASC')
			->addOrderBy('subject_id', 'ASC')
			->addOrderBy('location_id', 'ASC');
		$res = $qb->executeQuery();
		$out = [];
		while (($row = $res->fetch()) !== false) {
			$out[] = [
				'subjectType' => (string)$row['subject_type'],
				'subjectId' => (string)$row['subject_id'],
				'locationId' => (int)$row['location_id'],
			];
		}
		$res->closeCursor();
		return $out;
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return list<array{subjectType: string, subjectId: string, locationId: int}>
	 */
	public function replaceAll(string $actorUid, array $rows): array
	{
		$this->access->requireAppAdmin($actorUid);
		$clean = [];
		foreach ($rows as $row) {
			$type = strtolower(trim((string)($row['subjectType'] ?? $row['subject_type'] ?? '')));
			$id = trim((string)($row['subjectId'] ?? $row['subject_id'] ?? ''));
			$locId = (int)($row['locationId'] ?? $row['location_id'] ?? 0);
			if (!in_array($type, [self::TYPE_USER, self::TYPE_GROUP], true) || $id === '' || $locId <= 0) {
				throw new ValidationException('validation_failed', '', [
					['field' => 'acl', 'code' => 'validation_failed'],
				]);
			}
			if ($type === self::TYPE_USER && !$this->userManager->userExists($id)) {
				throw new ValidationException('unknown_user', 'Unknown user: ' . $id, [
					['field' => 'subjectId', 'code' => 'unknown_user'],
				]);
			}
			if ($type === self::TYPE_GROUP && !$this->groupManager->groupExists($id)) {
				throw new ValidationException('unknown_group', 'Unknown group: ' . $id, [
					['field' => 'subjectId', 'code' => 'unknown_group'],
				]);
			}
			$this->locations->findById($locId);
			$clean[] = [$type, $id, $locId];
		}
		$this->db->beginTransaction();
		try {
			$del = $this->db->getQueryBuilder();
			$del->delete('iv_loc_acl')->executeStatement();
			foreach ($clean as [$type, $id, $locId]) {
				$ins = $this->db->getQueryBuilder();
				$ins->insert('iv_loc_acl')->values([
					'subject_type' => $ins->createNamedParameter($type),
					'subject_id' => $ins->createNamedParameter($id),
					'location_id' => $ins->createNamedParameter($locId, IQueryBuilder::PARAM_INT),
				])->executeStatement();
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
		return $this->listAll();
	}
}
