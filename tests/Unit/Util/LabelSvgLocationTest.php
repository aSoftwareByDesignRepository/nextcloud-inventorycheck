<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Util;

use OCA\InventoryCheck\Util\LabelSvg;
use PHPUnit\Framework\TestCase;

final class LabelSvgLocationTest extends TestCase
{
	public function testForLocationContainsCodeAndKind(): void
	{
		$svg = LabelSvg::forLocation('SHELF-A3', 'Shelf A3', 'shelf');
		$this->assertStringContainsString('SHELF-A3', $svg);
		$this->assertStringContainsString('Shelf A3', $svg);
		$this->assertStringContainsString('shelf', $svg);
		$this->assertStringContainsString('role="img"', $svg);
		$this->assertStringContainsString('Location · QR · Code 128', $svg);
	}

	public function testEmptyCodeThrows(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		LabelSvg::forLocation('  ', 'x');
	}
}
