<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Util;

use OCA\InventoryCheck\Util\LabelSvg;
use PHPUnit\Framework\TestCase;

final class LabelSvgTest extends TestCase
{
	public function testRendersQrCode128AndTextAlternative(): void
	{
		$svg = LabelSvg::forItem('FILTER-42', 'FILTER-42', 'Cabin filter');
		self::assertStringContainsString('role="img"', $svg);
		self::assertStringContainsString('aria-label="FILTER-42"', $svg);
		self::assertStringContainsString('QR and barcode for FILTER-42', $svg);
		self::assertStringContainsString('id="iv-label-qr"', $svg);
		self::assertStringContainsString('id="iv-label-barcode"', $svg);
		self::assertStringContainsString('data-symbology="code128b"', $svg);
		self::assertStringContainsString('data-payload="FILTER-42"', $svg);
		self::assertStringContainsString('id="iv-label-code"', $svg);
		self::assertStringContainsString('>FILTER-42</text>', $svg);
		self::assertStringContainsString('SKU FILTER-42', $svg);
		self::assertStringContainsString('Cabin filter', $svg);
		self::assertStringContainsString('QR · Code 128', $svg);
		self::assertStringContainsString('<rect ', $svg);
		self::assertGreaterThan(30, substr_count($svg, '<rect '));
	}

	public function testEscapesXmlSpecialCharsInPayload(): void
	{
		$svg = LabelSvg::forItem('A&B', 'A&B', 'Name <x>');
		self::assertStringContainsString('A&amp;B', $svg);
		self::assertStringContainsString('Name &lt;x&gt;', $svg);
		self::assertStringNotContainsString('Name <x>', $svg);
		self::assertStringContainsString('data-payload="A&amp;B"', $svg);
	}

	public function testEmptyScanCodeRejected(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		LabelSvg::forItem('  ', 'X', 'Y');
	}

	public function testQrAndBarcodeShareIdenticalPayload(): void
	{
		$svg = LabelSvg::forItem('VAN/BOX-9', 'VAN/BOX-9', 'Van kit');
		self::assertMatchesRegularExpression('/data-payload="VAN\/BOX-9"/', $svg);
		self::assertStringContainsString('>VAN/BOX-9</text>', $svg);
		self::assertStringContainsString('aria-label="VAN/BOX-9"', $svg);
	}
}
