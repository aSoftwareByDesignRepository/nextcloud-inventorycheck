<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Support;

use OCA\InventoryCheck\Support\IconCatalog;
use PHPUnit\Framework\TestCase;

final class IconCatalogTest extends TestCase
{
	public function testKnownIconsRenderSvgWithA11yDefaults(): void
	{
		foreach (['layout-grid', 'list-checks', 'map-pin', 'history', 'settings', 'inbox'] as $name) {
			$svg = IconCatalog::render($name);
			self::assertStringContainsString('<svg', $svg);
			self::assertStringContainsString('aria-hidden="true"', $svg);
			self::assertStringContainsString('focusable="false"', $svg);
			self::assertStringNotContainsString('<script', $svg);
		}
	}

	public function testUnknownIconReturnsEmpty(): void
	{
		self::assertSame('', IconCatalog::render('not-a-real-icon-xyz'));
	}

	public function testNamesListsCatalog(): void
	{
		$names = IconCatalog::names();
		self::assertContains('layout-grid', $names);
		self::assertContains('map-pin', $names);
	}
}
