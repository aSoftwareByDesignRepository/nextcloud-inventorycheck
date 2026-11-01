<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\InventoryCheck\Tests\Unit\Support;

use OCA\InventoryCheck\Support\RouteUrlTemplate;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class RouteUrlTemplateTest extends TestCase
{
	private function urlGeneratorReturning(string $url): IURLGenerator
	{
		$gen = $this->createMock(IURLGenerator::class);
		$gen->method('linkToRoute')->willReturn($url);
		return $gen;
	}

	public function testSentinelReplacesNumericSegment(): void
	{
		$gen = $this->urlGeneratorReturning('/apps/inventorycheck/api/items/0/photo');
		$this->assertSame(
			'/apps/inventorycheck/api/items/__ID__/photo',
			RouteUrlTemplate::for($gen, 'inventorycheck.itemPhoto.show')
		);
	}

	public function testSentinelAtPathEnd(): void
	{
		$gen = $this->urlGeneratorReturning('/index.php/apps/inventorycheck/api/cycle-counts/0/close');
		$this->assertSame(
			'/index.php/apps/inventorycheck/api/cycle-counts/__ID__/close',
			RouteUrlTemplate::for($gen, 'inventorycheck.cycleCount.close')
		);
	}

	public function testCustomParamAndSentinel(): void
	{
		$gen = $this->createMock(IURLGenerator::class);
		$gen->expects($this->once())
			->method('linkToRoute')
			->with('inventorycheck.cycleCount.setCount', ['lineId' => 0])
			->willReturn('/apps/inventorycheck/api/cycle-counts/lines/0/count');
		$this->assertSame(
			'/apps/inventorycheck/api/cycle-counts/lines/__ID__/count',
			RouteUrlTemplate::for($gen, 'inventorycheck.cycleCount.setCount', 'lineId')
		);
	}

	public function testNumericInsideSegmentIsNotReplaced(): void
	{
		$gen = $this->urlGeneratorReturning('/apps/inventorycheck/locations/0/label');
		$this->assertSame(
			'/apps/inventorycheck/locations/__LOC_ID__/label',
			RouteUrlTemplate::for($gen, 'inventorycheck.location.label', 'id', '__LOC_ID__')
		);
	}

	public function testFailsLoudlyWhenSegmentMissing(): void
	{
		$gen = $this->urlGeneratorReturning('/apps/inventorycheck/api/items');
		$this->expectException(\RuntimeException::class);
		RouteUrlTemplate::for($gen, 'inventorycheck.item.index');
	}
}
