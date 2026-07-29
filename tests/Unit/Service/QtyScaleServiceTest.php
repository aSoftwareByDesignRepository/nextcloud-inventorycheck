<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\QtyScale;
use OCA\InventoryCheck\Service\QtyScaleService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

final class QtyScaleServiceTest extends TestCase
{
	public function testEnableFractionalIsNoOpWhenAlreadyMilli(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				if ($app === Application::APP_ID && $key === QtyScale::KEY) {
					return '3';
				}
				return $default;
			},
		);
		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::never())->method('beginTransaction');
		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects(self::never())->method('acquireLock');

		$svc = new QtyScaleService($db, $config, $locking);
		$result = $svc->enableFractional();
		self::assertSame(['qtyScale' => 3, 'changed' => false], $result);
	}

	public function testEnableFractionalMigratesAndSetsScale(): void
	{
		$scale = '0';
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$scale): string {
				if ($app === Application::APP_ID && $key === QtyScale::KEY) {
					return $scale;
				}
				return $default;
			},
		);
		$config->method('getSystemValue')->willReturnCallback(
			static function (string $key, mixed $default = '') {
				return $key === 'dbtableprefix' ? 'oc_' : $default;
			},
		);
		$config->expects(self::once())->method('setAppValue')->with(
			Application::APP_ID,
			QtyScale::KEY,
			'3',
		)->willReturnCallback(static function () use (&$scale): void {
			$scale = '3';
		});

		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::once())->method('beginTransaction');
		$db->expects(self::once())->method('commit');
		$db->method('tableExists')->willReturn(false);
		$db->expects(self::exactly(3))->method('executeStatement')->with(
			self::callback(static function (string $sql): bool {
				return str_contains($sql, ' * ' . QtyScale::FACTOR)
					&& (str_contains($sql, 'iv_balances')
						|| str_contains($sql, 'iv_movements')
						|| str_contains($sql, 'iv_items'));
			}),
		);

		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects(self::once())->method('acquireLock');
		$locking->expects(self::once())->method('releaseLock');

		$svc = new QtyScaleService($db, $config, $locking);
		$result = $svc->enableFractional();
		self::assertSame(['qtyScale' => 3, 'changed' => true], $result);
	}

	public function testSourceContractUsesFactorAndDoubleCheck(): void
	{
		$src = (string)file_get_contents(
			dirname(__DIR__, 3) . '/lib/Service/QtyScaleService.php',
		);
		self::assertStringContainsString('qty_scale_migration_in_progress', $src);
		self::assertStringContainsString('QtyScale::FACTOR', $src);
		self::assertStringContainsString('SCALE_MILLI', $src);
		// Double-checked locking: early return + re-check inside exclusive lock.
		self::assertSame(2, substr_count($src, 'QtyScale::current($this->config) === QtyScale::SCALE_MILLI'));
	}
}
