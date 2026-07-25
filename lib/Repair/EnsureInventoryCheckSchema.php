<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Repair;

use OC\DB\Connection;
use OC\DB\MigrationService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCP\Server;

final class EnsureInventoryCheckSchema implements IRepairStep
{
	public const DEMO_SEEDED_KEY = 'demo_seeded';

	/** @var list<array{code: string, name: string, kind: string}> */
	public const SEED_LOCATIONS = [
		['code' => 'WH', 'name' => 'Warehouse', 'kind' => 'warehouse'],
		['code' => 'VAN-1', 'name' => 'Van 1', 'kind' => 'van'],
		['code' => 'SITE-A', 'name' => 'Site box A', 'kind' => 'site'],
	];

	/** @var list<array{sku: string, name: string, uom: string, reorder: int}> */
	public const SEED_ITEMS = [
		['sku' => 'FILTER-42', 'name' => 'Air filter 42', 'uom' => 'pcs', 'reorder' => 5],
		['sku' => 'SEAL-10', 'name' => 'Seal kit 10', 'uom' => 'pcs', 'reorder' => 10],
		['sku' => 'CABLE-5M', 'name' => 'Cable 5 m', 'uom' => 'pcs', 'reorder' => 20],
	];

	public function __construct(
		private readonly IDBConnection $connection,
		private readonly IConfig $config,
	) {
	}

	public function getName(): string
	{
		return 'Ensure InventoryCheck database schema is complete';
	}

	public function run(IOutput $output): void
	{
		$this->config->deleteAppValue(UninstallDropTables::APP_ID, UninstallDropTables::REPAIR_PASS_KEY);

		$missingBefore = $this->missingTables();
		if ($missingBefore !== []) {
			$output->info(sprintf(
				'InventoryCheck: %d table(s) missing (%s); running pending migrations.',
				count($missingBefore),
				implode(', ', $missingBefore),
			));

			$migrationService = new MigrationService(
				UninstallDropTables::APP_ID,
				Server::get(Connection::class),
			);
			$migrationService->migrate('latest', false);

			$missingAfter = $this->missingTables();
			if ($missingAfter !== []) {
				throw new \RuntimeException(sprintf(
					'InventoryCheck schema is still incomplete after migrate("latest"). Missing: %s.',
					implode(', ', $missingAfter),
				));
			}
		}

		$seeded = $this->seedDemo();
		$output->info(sprintf(
			'InventoryCheck: all %d tables are present; seeded %d demo row(s).',
			count(UninstallDropTables::TABLES),
			$seeded,
		));
	}

	private function seedDemo(): int
	{
		if ($this->config->getAppValue(UninstallDropTables::APP_ID, self::DEMO_SEEDED_KEY, '0') === '1') {
			return 0;
		}

		$inserted = 0;
		$now = time();
		foreach (self::SEED_LOCATIONS as $row) {
			$qb = $this->connection->getQueryBuilder();
			$qb->select('id')->from('iv_locations')
				->where($qb->expr()->eq('code', $qb->createNamedParameter($row['code'])));
			$result = $qb->executeQuery();
			$exists = $result->fetch() !== false;
			$result->closeCursor();
			if ($exists) {
				continue;
			}
			$qb = $this->connection->getQueryBuilder();
			$qb->insert('iv_locations')->values([
				'code' => $qb->createNamedParameter($row['code']),
				'name' => $qb->createNamedParameter($row['name']),
				'kind' => $qb->createNamedParameter($row['kind']),
				'active' => $qb->createNamedParameter(true, \PDO::PARAM_BOOL),
				'created_at' => $qb->createNamedParameter($now, \PDO::PARAM_INT),
				'updated_at' => $qb->createNamedParameter($now, \PDO::PARAM_INT),
				'created_by' => $qb->createNamedParameter('system'),
			]);
			$qb->executeStatement();
			$inserted++;
		}
		foreach (self::SEED_ITEMS as $row) {
			$qb = $this->connection->getQueryBuilder();
			$qb->select('id')->from('iv_items')
				->where($qb->expr()->eq('sku', $qb->createNamedParameter($row['sku'])));
			$result = $qb->executeQuery();
			$exists = $result->fetch() !== false;
			$result->closeCursor();
			if ($exists) {
				continue;
			}
			$qb = $this->connection->getQueryBuilder();
			$qb->insert('iv_items')->values([
				'sku' => $qb->createNamedParameter($row['sku']),
				'scan_code' => $qb->createNamedParameter($row['sku']),
				'name' => $qb->createNamedParameter($row['name']),
				'uom' => $qb->createNamedParameter($row['uom']),
				'reorder_level' => $qb->createNamedParameter($row['reorder'], \PDO::PARAM_INT),
				'active' => $qb->createNamedParameter(true, \PDO::PARAM_BOOL),
				'created_at' => $qb->createNamedParameter($now, \PDO::PARAM_INT),
				'updated_at' => $qb->createNamedParameter($now, \PDO::PARAM_INT),
				'created_by' => $qb->createNamedParameter('system'),
			]);
			$qb->executeStatement();
			$inserted++;
		}

		$this->config->setAppValue(UninstallDropTables::APP_ID, self::DEMO_SEEDED_KEY, '1');
		return $inserted;
	}

	/** @return list<string> */
	private function missingTables(): array
	{
		$missing = [];
		foreach (UninstallDropTables::TABLES as $table) {
			if (!$this->connection->tableExists($table)) {
				$missing[] = $table;
			}
		}
		return $missing;
	}
}
