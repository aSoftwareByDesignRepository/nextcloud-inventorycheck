<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Util;

use OCA\InventoryCheck\Util\Csv;
use OCA\InventoryCheck\Util\LabelSheet;
use OCA\InventoryCheck\Util\LabelSvg;
use PHPUnit\Framework\TestCase;

final class CsvAndLabelSheetTest extends TestCase
{
	public function testSanitizeNeutralizesFormulaInjection(): void
	{
		self::assertSame("'=1+1", Csv::sanitizeField('=1+1'));
		self::assertSame("'+cmd", Csv::sanitizeField('+cmd'));
		self::assertSame("'-1", Csv::sanitizeField('-1'));
		self::assertSame("'@x", Csv::sanitizeField('@x'));
		self::assertSame("ok", Csv::sanitizeField('ok'));
		self::assertSame('', Csv::sanitizeField(''));
	}

	public function testLineQuotesAndJoinsWithSemicolon(): void
	{
		$line = Csv::line(['a', 'b;c', 'say "hi"']);
		self::assertSame("\"a\";\"b;c\";\"say \"\"hi\"\"\"\n", $line);
	}

	public function testWithBomPrefixesOnce(): void
	{
		$body = Csv::withBom("x\n");
		self::assertSame("\xEF\xBB\xBF", substr($body, 0, 3));
		self::assertStringEndsWith("x\n", $body);
	}

	public function testParseHandlesBomSemicolonAndQuotedCells(): void
	{
		$raw = "\xEF\xBB\xBF" . "sku;name;reorder_level\n" . "\"FILTER-1\";\"Air, filter\";5\n";
		$rows = Csv::parse($raw);
		self::assertCount(1, $rows);
		self::assertSame('FILTER-1', $rows[0]['sku']);
		self::assertSame('Air, filter', $rows[0]['name']);
		self::assertSame('5', $rows[0]['reorder_level']);
	}

	public function testParseLowercasesHeaders(): void
	{
		$rows = Csv::parse("SKU;Name;Reorder_Level\nX-1;Widget;2\n");
		self::assertArrayHasKey('sku', $rows[0]);
		self::assertArrayHasKey('name', $rows[0]);
		self::assertArrayHasKey('reorder_level', $rows[0]);
		self::assertSame('X-1', $rows[0]['sku']);
		self::assertSame('Widget', $rows[0]['name']);
		self::assertSame('2', $rows[0]['reorder_level']);
	}

	public function testParseAcceptsGermanHeaders(): void
	{
		$rows = Csv::parse("Artikelnummer;Scancode;Bezeichnung;Einheit;Mindestbestand\nDE-1;DE-1;Dichtung;Stk;3\n");
		self::assertCount(1, $rows);
		self::assertSame('DE-1', $rows[0]['sku']);
		self::assertSame('DE-1', $rows[0]['scan_code']);
		self::assertSame('Dichtung', $rows[0]['name']);
		self::assertSame('Stk', $rows[0]['uom']);
		self::assertSame('3', $rows[0]['reorder_level']);
	}

	public function testLocalizeHeadersGerman(): void
	{
		$de = Csv::localizeHeaders(['sku', 'scan_code', 'name'], 'de');
		self::assertSame(['Artikelnummer', 'Scancode', 'Bezeichnung'], $de);
		$en = Csv::localizeHeaders(['sku', 'name'], 'en');
		self::assertSame(['sku', 'name'], $en);
	}

	public function testParseEmptyReturnsEmpty(): void
	{
		self::assertSame([], Csv::parse(""));
		self::assertSame([], Csv::parse("\n\n"));
	}

	public function testLabelSheetCapacityAndHtml(): void
	{
		self::assertSame(12, LabelSheet::capacity());
		self::assertGreaterThanOrEqual(LabelSheet::MIN_LABELS_PER_A4, LabelSheet::capacity());
		$html = LabelSheet::html([
			['scanCode' => 'FILTER-42', 'sku' => 'FILTER-42', 'name' => 'Air filter'],
			['scanCode' => 'SEAL-10', 'sku' => 'SEAL-10', 'name' => 'Seal'],
		]);
		self::assertStringContainsString('iv-label-tile', $html);
		self::assertStringContainsString('iv-label-sheet__page', $html);
		self::assertStringContainsString('FILTER-42', $html);
		self::assertStringContainsString('SEAL-10', $html);
		self::assertStringNotContainsString('<?xml', $html);
	}

	public function testLabelSheetChunksIntoMultiplePages(): void
	{
		$items = [];
		for ($i = 0; $i < 13; $i++) {
			$code = 'L-' . $i;
			$items[] = ['scanCode' => $code, 'sku' => $code, 'name' => 'Item ' . $i];
		}
		$html = LabelSheet::html($items);
		self::assertSame(2, substr_count($html, 'iv-label-sheet__page'));
	}

	public function testLabelSheetRejectsEmpty(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		LabelSheet::html([]);
	}

	public function testLabelSvgRequiresScanCode(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		LabelSvg::forItem('', 'SKU', 'Name');
	}
}
