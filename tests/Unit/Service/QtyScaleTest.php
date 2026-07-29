<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\QtyScale;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class QtyScaleTest extends TestCase
{
	private function config(string $scale = '0'): IConfig
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($scale): string {
				if ($app === Application::APP_ID && $key === QtyScale::KEY) {
					return $scale;
				}
				return $default;
			},
		);
		return $config;
	}

	public function testIntScaleRejectsFractionsAndMapsInts(): void
	{
		$c = $this->config('0');
		self::assertSame(0, QtyScale::current($c));
		self::assertSame(1, QtyScale::factor($c));
		self::assertSame(12, QtyScale::toStorage($c, 12));
		self::assertSame(12, QtyScale::toStorage($c, '12'));
		self::assertSame(12, QtyScale::toDisplay($c, 12));
		$this->expectException(ValidationException::class);
		QtyScale::toStorage($c, '1.5');
	}

	public function testMilliScaleRoundTripsThreeDecimals(): void
	{
		$c = $this->config('3');
		self::assertSame(3, QtyScale::current($c));
		self::assertSame(1000, QtyScale::serialUnit($c));
		self::assertSame(1500, QtyScale::toStorage($c, '1.5'));
		self::assertSame(1001, QtyScale::toStorage($c, '1.001'));
		self::assertSame('1.5', QtyScale::toDisplay($c, 1500));
		self::assertSame('1.001', QtyScale::toDisplay($c, 1001));
		self::assertSame('2', QtyScale::toDisplay($c, 2000));
		self::assertSame('-1.25', QtyScale::toDisplay($c, -1250));
	}

	public function testMilliScaleRejectsTooManyDecimals(): void
	{
		$c = $this->config('3');
		$this->expectException(ValidationException::class);
		QtyScale::toStorage($c, '1.0001');
	}

	public function testFormattersOnlyTouchQtyFields(): void
	{
		$c = $this->config('3');
		$item = QtyScale::formatItem(['id' => 1, 'reorderLevel' => 2500, 'name' => 'x'], $c);
		self::assertSame('2.5', $item['reorderLevel']);
		self::assertSame('x', $item['name']);

		$bal = QtyScale::formatBalance(['qty' => 1000, 'itemId' => 9], $c);
		self::assertSame('1', $bal['qty']);

		$mov = QtyScale::formatMovement(['qtyDelta' => -500, 'qtyAfter' => 1500], $c);
		self::assertSame('-0.5', $mov['qtyDelta']);
		self::assertSame('1.5', $mov['qtyAfter']);

		$low = QtyScale::formatLowStock(['totalQty' => 900, 'reorderLevel' => 1000], $c);
		self::assertSame('0.9', $low['totalQty']);
		self::assertSame('1', $low['reorderLevel']);
	}
}
