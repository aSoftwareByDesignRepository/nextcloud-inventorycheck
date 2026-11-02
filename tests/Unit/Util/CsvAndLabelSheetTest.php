<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Util;

use OCA\InventoryCheck\Service\CsvImportService;
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
		self::assertSame("'\t=1+1", Csv::sanitizeField("\t=1+1"));
		self::assertSame("'\r=1+1", Csv::sanitizeField("\r=1+1"));
		self::assertSame("'\n=1+1", Csv::sanitizeField("\n=1+1"));
		self::assertSame("'\x0B=1+1", Csv::sanitizeField("\x0B=1+1"));
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

	/**
	 * Round-trip contract (intake 20261102T011538Z): every canonical key
	 * canonicalizeHeader can emit is either read by the items importer or
	 * documented export-only — a key nothing reads is a silently-dropped
	 * column. Reorder-export price must reach last_price_minor, EN and DE.
	 */
	public function testCanonicalKeysAreConsumedOrDocumentedExportOnly(): void
	{
		$rawHeaders = [
			'artikelnummer', 'artikel_nr', 'artikelnummer_sku', 'scancode', 'scan_code',
			'barcode', 'bezeichnung', 'artikelname', 'beschreibung', 'einheit',
			'mindestbestand', 'reorderlevel', 'zielbestand', 'lagerort',
			'opening_location', 'oeffnungs_lagerort', 'anfangsbestand',
			'opening_quantity', 'aktiv', 'lieferant', 'lieferantennotiz',
			'letzter_preis_cent', 'last_price', 'einzelpreis_cent',
			'unit_price_minor', 'lieferantenname', 'lieferant_name',
			'lieferanten_artikelnummer', 'lieferanten_sku', 'packungsgroesse',
			'packungsgroesse_einheiten', 'units_per_pack', 'packungspreis_cent',
			'preis_pro_packung_cent', 'lieferzeit_tage', 'lieferzeit', 'kategorie',
			'kritikalitaet', 'kritisch', 'geschaetzte_zeile_cent',
			'geschaetzte_kosten_cent', 'vorschlagsmenge', 'packungen', 'bestand',
			'ortscode', 'lagerort_code', 'typ', 'notizen', 'menge', 'artikel_id',
			'lagerort_id', 'mengen_delta', 'menge_delta', 'menge_nachher',
			'bestand_nachher', 'erstellt_am', 'erstellt_von', 'buchungsart',
		];
		$allowed = array_merge(CsvImportService::CONSUMED_COLUMNS, Csv::EXPORT_ONLY_KEYS);
		foreach ($rawHeaders as $raw) {
			$canonical = Csv::canonicalizeHeader($raw);
			self::assertContains(
				$canonical,
				$allowed,
				"canonical key '{$canonical}' (from '{$raw}') is neither imported nor documented export-only",
			);
		}
		self::assertSame(
			[],
			array_intersect(CsvImportService::CONSUMED_COLUMNS, Csv::EXPORT_ONLY_KEYS),
			'consumed and export-only sets must be disjoint',
		);
	}

	public function testReorderExportPriceHeaderRoundTripsToLastPrice(): void
	{
		// EN reorder export emits unit_price_minor; DE emits Einzelpreis_Cent.
		// Both carry the item's last price and must feed last_price_minor.
		self::assertSame('last_price_minor', Csv::canonicalizeHeader('unit_price_minor'));
		self::assertSame('last_price_minor', Csv::canonicalizeHeader('einzelpreis_cent'));
		self::assertSame('last_price_minor', Csv::canonicalizeHeader('Einzelpreis_Cent'));
		$rows = Csv::parse("unit_price_minor;sku;name\n12345;R-1;Roundtrip\n");
		self::assertSame('12345', $rows[0]['last_price_minor']);
	}

	public function testOwnExportHeadersCanonicalizeToKnownKeys(): void
	{
		$exportSets = [
			// starter template (ExportController::starter)
			['sku', 'name', 'uom', 'reorder_level', 'target_stock', 'scan_code', 'description'],
			// items export (CsvExportService::exportItems)
			['sku', 'scan_code', 'name', 'description', 'uom', 'reorder_level', 'active',
				'supplier_note', 'last_price_minor', 'supplier_name', 'supplier_sku',
				'order_pack_qty', 'order_pack_price_minor', 'supplier_lead_days',
				'category', 'criticality'],
			// reorder export (CsvExportService::exportReorder)
			['sku', 'name', 'on_hand', 'reorder_level', 'target_stock', 'suggested_qty',
				'supplier_note', 'uom', 'supplier_name', 'supplier_sku',
				'unit_price_minor', 'order_pack_qty', 'packs', 'est_line_minor', 'category'],
		];
		$allowed = array_merge(CsvImportService::CONSUMED_COLUMNS, Csv::EXPORT_ONLY_KEYS);
		foreach ($exportSets as $headers) {
			foreach (['en', 'de'] as $lang) {
				foreach (Csv::localizeHeaders($headers, $lang) as $label) {
					$canonical = Csv::canonicalizeHeader($label);
					self::assertContains(
						$canonical,
						$allowed,
						"export header '{$label}' ({$lang}) canonicalizes to unclassified '{$canonical}'",
					);
				}
			}
		}
		// The whole DE reorder row must round-trip — not just the price column.
		$deRow = 'Artikelnummer;Bezeichnung;Einzelpreis_Cent;Zielbestand;Lieferant_Name' . "\n"
			. 'D-1;Dichtung;999;50;Lieferant GmbH' . "\n";
		$parsed = Csv::parse($deRow)[0];
		self::assertSame('999', $parsed['last_price_minor']);
		self::assertSame('50', $parsed['target_stock']);
		self::assertSame('Lieferant GmbH', $parsed['supplier_name']);
	}

	public function testLabelSheetCapacityAndHtml(): void
	{
		self::assertSame(12, LabelSheet::capacity());
		self::assertSame(500, LabelSheet::MAX_BULK_LABELS);
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
