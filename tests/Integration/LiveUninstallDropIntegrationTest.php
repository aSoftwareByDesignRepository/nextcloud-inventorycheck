<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Repair\EnsureInventoryCheckSchema;
use OCA\InventoryCheck\Repair\UninstallDropTables;
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
		$this->assertSame('yes', $preserved['enabled'] ?? '', 'precondition: app must be enabled before AC-20 drop');

		foreach (UninstallDropTables::TABLES as $table) {
			$this->assertTrue($db->tableExists($table), "precondition: $table exists");
		}

		try {
			$step = Server::get(UninstallDropTables::class);
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
