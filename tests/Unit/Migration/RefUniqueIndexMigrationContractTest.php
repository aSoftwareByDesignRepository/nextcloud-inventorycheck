<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Migration;

use OCA\InventoryCheck\Migration\Version1004Date20260726180000;
use PHPUnit\Framework\TestCase;

/** FC-IV-ISSUE — unique flange idempotency index must stay declared. */
final class RefUniqueIndexMigrationContractTest extends TestCase
{
	public function testMigrationDeclaresUniqueRefIndex(): void
	{
		$path = dirname(__DIR__, 3) . '/lib/Migration/Version1004Date20260726180000.php';
		$this->assertFileExists($path);
		$src = (string)file_get_contents($path);
		$this->assertStringContainsString('iv_mov_ref_uq', $src);
		$this->assertStringContainsString("addUniqueIndex(['ref_type', 'ref_id', 'item_id']", $src);
		$this->assertTrue(class_exists(Version1004Date20260726180000::class));
	}

	public function testFacadeHandlesUniqueConstraintAsIdempotentReplay(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Public/StockIssueFacade.php');
		$this->assertStringContainsString('REASON_UNIQUE_CONSTRAINT_VIOLATION', $src);
		$this->assertStringContainsString('replayAfterUniqueRace', $src);
		$this->assertStringContainsString('idempotent_replay', $src);
	}
}
