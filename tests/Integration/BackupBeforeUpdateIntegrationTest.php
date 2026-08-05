<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\Repair\BackupBeforeUpdate;
use OCP\Migration\IOutput;
use Test\TestCase;

final class BackupBeforeUpdateIntegrationTest extends TestCase
{
	public function testPreMigrationRepairStepRunsInContainer(): void
	{
		/** @var BackupBeforeUpdate $step */
		$step = \OC::$server->get(BackupBeforeUpdate::class);
		$output = $this->createMock(IOutput::class);
		// Success → info; oversized ledger soft-continue → warning only.
		$progress = 0;
		$output->method('info')->willReturnCallback(static function () use (&$progress): void {
			$progress++;
		});
		$output->method('warning')->willReturnCallback(static function () use (&$progress): void {
			$progress++;
		});

		$step->run($output);
		self::assertGreaterThan(0, $progress, 'repair must emit info (snapshot) or warning (oversized skip)');
	}
}
