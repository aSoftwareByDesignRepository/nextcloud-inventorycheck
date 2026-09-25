<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Repair\EnsureInventoryCheckSchema;
use OCA\InventoryCheck\Repair\UninstallDropTables;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Server;
use Test\TestCase;

/**
 * AC-20 / N7 — explicit removal drops every iv_* table; schema ensurer restores them.
 *
 * Uses reflection on the private drop path (same code Installer::removeApp runs).
 * Snapshots appconfig and restores it in finally so later suites / live Docker stay up.
 *
 * @group DB
 */
final class LiveUninstallDropIntegrationTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		if (!class_exists(\OC::class)) {
			$this->markTestSkipped('Nextcloud runtime required');
		}
	}

	public function testRemovalDropClearsAllTablesAndEnsurerRestoresThem(): void
	{
		$db = Server::get(IDBConnection::class);
		$config = Server::get(IConfig::class);
		$appId = Application::APP_ID;

		$preserved = $this->snapshotAppConfig($config, $appId);
		$preservedTables = $this->snapshotTables($db);
		$this->assertSame('yes', $preserved['enabled'] ?? '', 'precondition: app must be enabled before AC-20 drop');

		foreach (UninstallDropTables::TABLES as $table) {
			$this->assertTrue($db->tableExists($table), "precondition: $table exists");
		}

		try {
			// Stub the root folder: the real container instance would run
			// purgeAppData() against the live appdata dir and permanently
			// delete it on every suite run (CRIT-05). The stub still proves
			// the drop path resolves appdata_<id>/<app> and calls delete().
			$appDataDir = $this->createMock(Folder::class);
			$appDataDir->expects($this->once())->method('delete');
			$rootFolder = $this->createMock(IRootFolder::class);
			$rootFolder->method('get')
				->with('appdata_' . $config->getSystemValue('instanceid', '') . '/' . $appId)
				->willReturn($appDataDir);
			$step = new UninstallDropTables($db, $config, $rootFolder);
			// Access the private test-only path without ReflectionMethod::setAccessible()
			// (PHP 8.5 deprecates setAccessible()).
			\Closure::bind(
				function (IOutput $output): void {
					$this->dropAllTablesAndMetadata($output);
				},
				$step,
				UninstallDropTables::class,
			)($this->createMock(IOutput::class));

			foreach (UninstallDropTables::TABLES as $table) {
				$this->assertFalse($db->tableExists($table), "AC-20: $table must be gone after removal drop");
			}
			$this->assertSame(
				'',
				$config->getAppValue($appId, 'enabled', ''),
				'removal path clears appconfig (including enabled)',
			);

			Server::get(EnsureInventoryCheckSchema::class)->run($this->createMock(IOutput::class));

			foreach (UninstallDropTables::TABLES as $table) {
				$this->assertTrue($db->tableExists($table), "ensurer must recreate $table");
			}
		} finally {
			// Schema may be mid-restore on failure — ensure it exists before
			// writing rows back so the suite leaves the instance data-intact.
			if (!$db->tableExists('iv_items')) {
				Server::get(EnsureInventoryCheckSchema::class)->run($this->createMock(IOutput::class));
			}
			$this->restoreTables($db, $preservedTables);
			foreach ($preserved as $key => $value) {
				$config->setAppValue($appId, $key, $value);
			}
			$this->assertSame(
				'yes',
				$config->getAppValue($appId, 'enabled', ''),
				'AC-20 suite must leave the app enabled for the rest of the gauntlet',
			);
		}
	}

	/**
	 * @return array<string, list<array<string, mixed>>>
	 */
	private function snapshotTables(IDBConnection $db): array
	{
		$out = [];
		foreach (UninstallDropTables::TABLES as $table) {
			if (!$db->tableExists($table)) {
				continue;
			}
			$res = $db->getQueryBuilder()->select('*')->from($table)->executeQuery();
			$out[$table] = $res->fetchAll();
			$res->closeCursor();
		}
		return $out;
	}

	/**
	 * @param array<string, list<array<string, mixed>>> $tables
	 */
	private function restoreTables(IDBConnection $db, array $tables): void
	{
		foreach ($tables as $table => $rows) {
			if (!$db->tableExists($table)) {
				continue;
			}
			// Wipe whatever the drop/ensurer left (incl. re-seeded demo rows)
			// so the instance returns to its exact pre-test contents.
			$db->getQueryBuilder()->delete($table)->executeStatement();
			foreach ($rows as $row) {
				$ins = $db->getQueryBuilder()->insert($table);
				foreach ($row as $col => $val) {
					$ins->setValue($col, $ins->createNamedParameter($val));
				}
				$ins->executeStatement();
			}
		}
	}

	/**
	 * @return array<string, string>
	 */
	private function snapshotAppConfig(IConfig $config, string $appId): array
	{
		$out = [];
		foreach ($config->getAppKeys($appId) as $key) {
			$out[$key] = $config->getAppValue($appId, $key, '');
		}
		return $out;
	}
}
