<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Util;

use OCA\InventoryCheck\Util\Code128Svg;
use PHPUnit\Framework\TestCase;

final class Code128SvgTest extends TestCase
{
	public function testSymbolValuesIncludeStartChecksumStop(): void
	{
		$values = Code128Svg::symbolValues('ABC');
		self::assertSame(104, $values[0], 'Start B');
		self::assertSame(33, $values[1], 'A');
		self::assertSame(34, $values[2], 'B');
		self::assertSame(35, $values[3], 'C');
		// checksum = (104 + 1*33 + 2*34 + 3*35) % 103 = 310 % 103 = 1
		self::assertSame(1, $values[4]);
		self::assertSame(106, $values[5], 'Stop');
	}

	public function testChecksumMatchesIsoWeightedMod103(): void
	{
		self::assertSame(1, Code128Svg::checksum([104, 33, 34, 35]));
		self::assertSame(20, Code128Svg::checksum([104, 38, 41, 44, 52, 37, 50, 13, 20, 18]));
	}

	public function testBarsGroupEncodesPayloadAttributeAndRects(): void
	{
		$svg = Code128Svg::barsGroup('FILTER-42', 0.0, 0.0, 280.0, 40.0);
		self::assertStringContainsString('id="iv-label-barcode"', $svg);
		self::assertStringContainsString('data-symbology="code128b"', $svg);
		self::assertStringContainsString('data-payload="FILTER-42"', $svg);
		self::assertStringContainsString('aria-label="Code 128 FILTER-42"', $svg);
		self::assertGreaterThan(10, substr_count($svg, '<rect '));
	}

	public function testEscapesXmlInPayloadAttribute(): void
	{
		$svg = Code128Svg::barsGroup('A&B', 0.0, 0.0, 100.0, 20.0);
		self::assertStringContainsString('data-payload="A&amp;B"', $svg);
		self::assertStringNotContainsString('data-payload="A&B"', $svg);
	}

	public function testRejectsEmptyPayload(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		Code128Svg::barsGroup('  ', 0.0, 0.0, 10.0, 10.0);
	}

	public function testRejectsNonCode128BCharset(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		Code128Svg::symbolValues("A\nB");
	}

	public function testS7ScanCharsetIsEncodable(): void
	{
		foreach (['SKU-1', 'a.b_c/d', 'Zz09._/-', str_repeat('X', 64)] as $code) {
			$values = Code128Svg::symbolValues($code);
			self::assertSame(104, $values[0]);
			self::assertSame(106, $values[array_key_last($values)]);
			self::assertGreaterThan(20, Code128Svg::totalModules($values));
		}
	}
}
