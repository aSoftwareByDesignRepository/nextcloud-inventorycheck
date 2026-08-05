<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Repair;

use OCA\InventoryCheck\Repair\BackupBeforeUpdate;
use OCA\InventoryCheck\Service\UpgradeBackupService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

final class BackupBeforeUpdateTest extends TestCase
{
	public function testSkipsWhenNoTablesExist(): void
	{
		$backup = $this->createMock(UpgradeBackupService::class);
		$backup->expects(self::once())->method('hasDataToBackup')->willReturn(false);
		$backup->expects(self::never())->method('createSnapshot');

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info');

		$step = new BackupBeforeUpdate($backup);
		$step->run($output);
	}

	public function testCreatesSnapshotWhenTablesExist(): void
	{
		$backup = $this->createMock(UpgradeBackupService::class);
		$backup->expects(self::once())->method('hasDataToBackup')->willReturn(true);
		$backup->expects(self::once())->method('createSnapshot')
			->with('pre-migration')
			->willReturn([
				'id' => '20260624T120000Z-deadbeef',
				'manifest' => ['tables' => ['pc_projects' => []]],
			]);

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info');

		$step = new BackupBeforeUpdate($backup);
		$step->run($output);
	}

	public function testPropagatesBackupFailure(): void
	{
		$backup = $this->createMock(UpgradeBackupService::class);
		$backup->method('hasDataToBackup')->willReturn(true);
		$backup->method('createSnapshot')->willThrowException(
			new \OCA\InventoryCheck\Exception\UpgradeBackupException('disk full'),
		);

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('warning');

		$this->expectException(\OCA\InventoryCheck\Exception\UpgradeBackupException::class);

		(new BackupBeforeUpdate($backup))->run($output);
	}

	public function testOversizedLedgerContinuesUpgradeWithoutSnapshot(): void
	{
		$backup = $this->createMock(UpgradeBackupService::class);
		$backup->method('hasDataToBackup')->willReturn(true);
		$backup->method('createSnapshot')->willThrowException(
			new \OCA\InventoryCheck\Exception\UpgradeBackupException(
				'Table iv_cc_line has 2500001 rows; exceeds backup row limit of 200000. Use a database-level dump for large instances.',
			),
		);

		$output = $this->createMock(IOutput::class);
		$output->expects(self::exactly(2))->method('warning');
		$output->expects(self::never())->method('info');

		(new BackupBeforeUpdate($backup))->run($output);
		$this->addToAssertionCount(1);
	}
}
