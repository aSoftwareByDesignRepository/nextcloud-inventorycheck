<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Db;

use PHPUnit\Framework\TestCase;

/**
 * N6 — portability contracts without requiring a second Docker DB in every run.
 * Live PostgreSQL CI remains a release checklist item; these guards keep the
 * code paths auditors care about from regressing on MariaDB-only CI.
 */
final class ProviderPortabilityContractTest extends TestCase
{
	public function testEnsureZeroRowUsesInsertIgnoreConflict(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Db/BalanceMapper.php');
		$this->assertStringContainsString('insertIgnoreConflict', $src);
		$this->assertStringContainsString('ensureZeroRow', $src);
	}

	public function testUninstallDropHandlesPostgresCascade(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Repair/UninstallDropTables.php');
		$this->assertStringContainsString('PLATFORM_POSTGRES', $src);
		$this->assertStringContainsString('CASCADE', $src);
		$this->assertStringContainsString('PLATFORM_MYSQL', $src);
	}

	public function testRowLocksDocumentForUpdateAndSqliteSkip(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Db/BalanceMapper.php');
		$this->assertStringContainsString('FOR UPDATE', $src);
		$this->assertStringContainsString('PLATFORM_SQLITE', $src);
	}

	public function testInfoXmlDeclaresMysqlAndPgsql(): void
	{
		$xml = (string)file_get_contents(dirname(__DIR__, 3) . '/appinfo/info.xml');
		$this->assertMatchesRegularExpression('/<database>.*?mysql.*?<\/database>/s', $xml);
		$this->assertMatchesRegularExpression('/<database>.*?pgsql.*?<\/database>/s', $xml);
	}
}
