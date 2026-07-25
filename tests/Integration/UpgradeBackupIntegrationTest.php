<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\Exception\UpgradeBackupException;
use OCA\InventoryCheck\Service\UpgradeBackupCatalog;
use OCA\InventoryCheck\Service\UpgradeBackupService;
use OCP\IDBConnection;
use Test\TestCase;

final class UpgradeBackupIntegrationTest extends TestCase
{
	private UpgradeBackupService $backupService;
	private IDBConnection $db;

	protected function setUp(): void
	{
		parent::setUp();
		$this->backupService = \OC::$server->get(UpgradeBackupService::class);
		$this->db = \OC::$server->get(IDBConnection::class);
	}

	public function testCreateListAndRestoreRoundTrip(): void
	{
		if (!$this->db->tableExists('iv_locations')) {
			self::markTestSkipped('InventoryCheck tables not present in this instance.');
		}

		$marker = 'UB-MARK-' . bin2hex(random_bytes(4));
		$now = time();
		$insert = $this->db->getQueryBuilder();
		$insert->insert('iv_locations')->values([
			'code' => $insert->createNamedParameter($marker),
			'name' => $insert->createNamedParameter('Upgrade backup marker'),
			'kind' => $insert->createNamedParameter('other'),
			'notes' => $insert->createNamedParameter(null),
			'active' => $insert->createNamedParameter(1, \PDO::PARAM_INT),
			'created_at' => $insert->createNamedParameter($now, \PDO::PARAM_INT),
			'updated_at' => $insert->createNamedParameter($now, \PDO::PARAM_INT),
			'created_by' => $insert->createNamedParameter('admin'),
		])->executeStatement();

		$result = $this->backupService->createSnapshot('integration-test');
		$snapshotId = $result['id'];
		self::assertNotSame('', $snapshotId);
		self::assertTrue($result['manifest']['complete'] ?? false);
		self::assertNotEmpty($result['manifest']['tables'] ?? [], 'Snapshot must include table metadata when tables exist.');

		$snapshots = $this->backupService->listSnapshots();
		$ids = array_map(static fn (array $snapshot): string => (string)($snapshot['id'] ?? ''), $snapshots);
		self::assertContains($snapshotId, $ids, 'listSnapshots must find the snapshot just created');

		$this->deleteByCode($marker);
		self::assertFalse($this->locationExists($marker));

		$this->backupService->restoreSnapshot($snapshotId, false);
		self::assertTrue($this->locationExists($marker), 'restore must bring marker location back');

		foreach (UpgradeBackupCatalog::BACKUP_TABLES as $table) {
			self::assertTrue($this->db->tableExists($table), "catalog table missing: $table");
		}

		$this->deleteByCode($marker);
	}

	public function testRestoreRejectsInvalidSnapshotId(): void
	{
		$this->expectException(UpgradeBackupException::class);
		$this->backupService->restoreSnapshot('../evil', false);
	}

	private function deleteByCode(string $code): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete('iv_locations')
			->where($qb->expr()->eq('code', $qb->createNamedParameter($code)))
			->executeStatement();
	}

	private function locationExists(string $code): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from('iv_locations')
			->where($qb->expr()->eq('code', $qb->createNamedParameter($code)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count > 0;
	}
}
