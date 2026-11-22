<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\LocationMapper;
use OCA\InventoryCheck\Db\MovementMapper;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\CsvExportService;
use OCA\InventoryCheck\Service\ReportService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * IV-REPORTS-SURFACE: dead_stock CSV kind — days bounds 1..3650 (NN-02)
 * enforced before any data is pulled; office gate first.
 */
final class CsvExportServiceReportsTest extends TestCase
{
	private function service(?ReportService $reports = null): CsvExportService
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('requireOffice');
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, $default = '') => $default);
		$reports ??= $this->createMock(ReportService::class);
		return new CsvExportService(
			$this->createMock(ItemMapper::class),
			$this->createMock(LocationMapper::class),
			$this->createMock(BalanceMapper::class),
			$this->createMock(MovementMapper::class),
			$access,
			$config,
			$reports,
		);
	}

	public function testDeadStockDaysLowerBoundEnforced(): void
	{
		$this->expectException(ValidationException::class);
		$this->service()->export('office-user', 'dead_stock', null, null, null, null, 'en', null, 0);
	}

	public function testDeadStockDaysUpperBoundEnforced(): void
	{
		$this->expectException(ValidationException::class);
		$this->service()->export('office-user', 'dead_stock', null, null, null, null, 'en', null, 3651);
	}

	public function testDeadStockDaysDefaultsTo90WhenNull(): void
	{
		$reports = $this->createMock(ReportService::class);
		$reports->expects($this->once())
			->method('deadStock')
			->with(90, $this->anything())
			->willReturn(['isEstimate' => true, 'days' => 90, 'rows' => [], 'unpricedCount' => 0]);
		$out = $this->service($reports)->export('office-user', 'dead_stock', null, null, null, null, 'en', null, null);
		$this->assertSame('inventorycheck-dead-stock-90d.csv', $out['filename']);
	}

	public function testBoundaryDays1And3650AreAccepted(): void
	{
		$reports = $this->createMock(ReportService::class);
		$reports->method('deadStock')
			->willReturn(['isEstimate' => true, 'days' => 1, 'rows' => [], 'unpricedCount' => 0]);
		foreach ([1, 3650] as $days) {
			$out = $this->service($reports)->export('office-user', 'dead_stock', null, null, null, null, 'en', null, $days);
			$this->assertStringContainsString((string)$days . 'd.csv', $out['filename']);
		}
	}
}
