<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Repair\UninstallDropTables;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\MovementService;
use OCP\IDBConnection;
use OCP\Server;
use Test\TestCase;

/**
 * @group DB
 */
final class SchemaAndContainerIntegrationTest extends TestCase
{
	public function testAllIvTablesExist(): void
	{
		$db = Server::get(IDBConnection::class);
		foreach (UninstallDropTables::TABLES as $table) {
			$this->assertTrue($db->tableExists($table), "missing table $table");
		}
		$this->assertCount(12, UninstallDropTables::TABLES);
	}

	public function testCoreServicesResolve(): void
	{
		$app = new Application();
		$c = $app->getContainer();
		$this->assertInstanceOf(AccessControlService::class, $c->get(AccessControlService::class));
		$this->assertInstanceOf(MovementService::class, $c->get(MovementService::class));
	}
}
