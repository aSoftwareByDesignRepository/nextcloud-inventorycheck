<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Repair;

use OCA\InventoryCheck\Repair\UninstallDropTables;
use OCA\InventoryCheck\Service\UpgradeBackupCatalog;
use PHPUnit\Framework\TestCase;

final class UninstallDropTablesTest extends TestCase
{
	public function testUninstallListUsesIvPrefixOnly(): void
	{
		foreach (UninstallDropTables::TABLES as $table) {
			self::assertStringStartsWith('iv_', $table, $table . ' must use iv_ prefix');
		}
	}

	public function testBackupCatalogMatchesUninstall(): void
	{
		$uninstall = UninstallDropTables::TABLES;
		sort($uninstall);
		$backup = UpgradeBackupCatalog::BACKUP_TABLES;
		sort($backup);
		self::assertSame($uninstall, $backup);
	}

	public function testUninstallListsAllSevenTables(): void
	{
		self::assertCount(7, UninstallDropTables::TABLES);
		self::assertContains('iv_movements', UninstallDropTables::TABLES);
		self::assertContains('iv_scan_devices', UninstallDropTables::TABLES);
	}

	public function testRepairStepNameIsDescriptive(): void
	{
		$step = new UninstallDropTables(
			$this->createMock(\OCP\IDBConnection::class),
			$this->createMock(\OCP\IConfig::class),
			$this->createMock(\OCP\Files\IRootFolder::class),
		);
		self::assertStringContainsString('inventorycheck', $step->getName());
		self::assertStringContainsString('uninstall', strtolower($step->getName()));
	}
}
