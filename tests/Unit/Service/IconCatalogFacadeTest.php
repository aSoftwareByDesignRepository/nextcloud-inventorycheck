<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Service\IconCatalog;
use OCA\InventoryCheck\Support\IconCatalog as SupportIconCatalog;
use PHPUnit\Framework\TestCase;

final class IconCatalogFacadeTest extends TestCase
{
	public function testServiceFacadeResolvesForSharedPartial(): void
	{
		self::assertTrue(class_exists(IconCatalog::class));
		self::assertTrue(method_exists(IconCatalog::class, 'has'));
		self::assertTrue(method_exists(IconCatalog::class, 'render'));
	}

	public function testHasDelegatesToSupportCatalog(): void
	{
		self::assertTrue(IconCatalog::has('info'));
		self::assertFalse(IconCatalog::has('not-a-real-icon-xyz'));
		foreach (SupportIconCatalog::names() as $name) {
			self::assertTrue(IconCatalog::has($name));
		}
	}

	public function testRenderDelegatesToSupportCatalog(): void
	{
		$svg = IconCatalog::render('info', 'iv-icon');
		self::assertStringContainsString('<svg', $svg);
		self::assertStringContainsString('iv-icon', $svg);
		self::assertSame('', IconCatalog::render('not-a-real-icon-xyz'));
	}
}
