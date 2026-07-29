<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Util;

/**
 * CSV dialect for InventoryCheck exports/imports (Check-family Excel-friendly).
 *
 * Separator `;`, every cell quoted, UTF-8 BOM added once by download helpers.
 */
final class Csv
{
	public const MAX_EXPORT_ROWS = 50000;
	public const MAX_IMPORT_ROWS = 2000;

	private function __construct()
	{
	}

	public static function sanitizeField(string $value): string
	{
		if ($value === '') {
			return $value;
		}
		$first = $value[0];
		if ($first === '=' || $first === '+' || $first === '-' || $first === '@' || $first === "\t" || $first === "\r") {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * @param array<int, string|int|float|null> $fields
	 */
	public static function line(array $fields): string
	{
		$cells = [];
		foreach ($fields as $field) {
			$value = self::sanitizeField((string)($field ?? ''));
			$cells[] = '"' . str_replace('"', '""', $value) . '"';
		}
		return implode(';', $cells) . "\n";
	}

	public static function withBom(string $body): string
	{
		return "\xEF\xBB\xBF" . $body;
	}

	/**
	 * Map DE/EN header aliases to the machine-stable English column keys (Wave A1).
	 */
	public static function canonicalizeHeader(string $header): string
	{
		$key = strtolower(trim($header));
		$key = str_replace([' ', '-'], '_', $key);
		return match ($key) {
			'artikelnummer', 'artikel_nr', 'artikelnummer_sku' => 'sku',
			'scancode', 'scan_code', 'barcode' => 'scan_code',
			'bezeichnung', 'artikelname' => 'name',
			'beschreibung' => 'description',
			'einheit' => 'uom',
			'mindestbestand', 'reorderlevel' => 'reorder_level',
			'lagerort', 'opening_location', 'oeffnungs_lagerort' => 'opening_location_code',
			'anfangsbestand', 'opening_quantity' => 'opening_qty',
			'aktiv' => 'active',
			'lieferant', 'lieferantennotiz' => 'supplier_note',
			'letzter_preis_cent', 'last_price' => 'last_price_minor',
			'ortscode', 'lagerort_code' => 'code',
			'typ' => 'kind',
			'notizen' => 'notes',
			'menge' => 'qty',
			'artikel_id' => 'item_id',
			'lagerort_id' => 'location_id',
			'mengen_delta', 'menge_delta' => 'qty_delta',
			'menge_nachher', 'bestand_nachher' => 'qty_after',
			'erstellt_am' => 'created_at',
			'erstellt_von' => 'created_by',
			'buchungsart' => 'kind',
			default => $key,
		};
	}

	/**
	 * Translate machine-stable English headers to German labels for DE exports.
	 *
	 * @param list<string> $headers
	 * @return list<string>
	 */
	public static function localizeHeaders(array $headers, string $lang): array
	{
		$lang = strtolower(substr(trim($lang), 0, 2));
		if ($lang !== 'de') {
			return $headers;
		}
		$map = [
			'sku' => 'Artikelnummer',
			'scan_code' => 'Scancode',
			'name' => 'Bezeichnung',
			'description' => 'Beschreibung',
			'uom' => 'Einheit',
			'reorder_level' => 'Mindestbestand',
			'active' => 'Aktiv',
			'supplier_note' => 'Lieferant',
			'last_price_minor' => 'Letzter_Preis_Cent',
			'code' => 'Ortscode',
			'kind' => 'Typ',
			'notes' => 'Notizen',
			'item_id' => 'Artikel_ID',
			'location_id' => 'Lagerort_ID',
			'qty' => 'Menge',
			'updated_at' => 'Aktualisiert_Am',
			'id' => 'ID',
			'qty_delta' => 'Mengen_Delta',
			'qty_after' => 'Menge_Nachher',
			'transfer_group' => 'Umbuchungsgruppe',
			'counterparty_loc_id' => 'Gegen_Lagerort_ID',
			'reason' => 'Grund',
			'ref_type' => 'Ref_Typ',
			'ref_id' => 'Ref_ID',
			'created_at' => 'Erstellt_Am',
			'created_by' => 'Erstellt_Von',
			'movement_id' => 'Buchungs_ID',
		];
		return array_map(static fn (string $h): string => $map[$h] ?? $h, $headers);
	}

	/**
	 * Parse semicolon-or-comma CSV text into associative rows using first line headers.
	 *
	 * @return list<array<string, string>>
	 */
	public static function parse(string $raw): array
	{
		$raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
		$raw = str_replace(["\r\n", "\r"], "\n", $raw);
		$lines = array_values(array_filter(explode("\n", $raw), static fn (string $l): bool => trim($l) !== ''));
		if ($lines === []) {
			return [];
		}
		$headerLine = array_shift($lines);
		$delimiter = self::detectDelimiter($headerLine);
		$headers = self::parseLine($headerLine, $delimiter);
		$headers = array_map(static fn (string $h): string => self::canonicalizeHeader($h), $headers);
		$rows = [];
		foreach ($lines as $line) {
			$cells = self::parseLine($line, $delimiter);
			$row = [];
			foreach ($headers as $i => $key) {
				if ($key === '') {
					continue;
				}
				$row[$key] = isset($cells[$i]) ? trim((string)$cells[$i]) : '';
			}
			$rows[] = $row;
		}
		return $rows;
	}

	private static function detectDelimiter(string $headerLine): string
	{
		$semi = substr_count($headerLine, ';');
		$comma = substr_count($headerLine, ',');
		return $semi >= $comma ? ';' : ',';
	}

	/**
	 * @return list<string>
	 */
	private static function parseLine(string $line, string $delimiter): array
	{
		$out = [];
		$len = strlen($line);
		$buf = '';
		$inQuotes = false;
		for ($i = 0; $i < $len; $i++) {
			$ch = $line[$i];
			if ($inQuotes) {
				if ($ch === '"') {
					if ($i + 1 < $len && $line[$i + 1] === '"') {
						$buf .= '"';
						$i++;
					} else {
						$inQuotes = false;
					}
				} else {
					$buf .= $ch;
				}
				continue;
			}
			if ($ch === '"') {
				$inQuotes = true;
				continue;
			}
			if ($ch === $delimiter) {
				$out[] = $buf;
				$buf = '';
				continue;
			}
			$buf .= $ch;
		}
		$out[] = $buf;
		return $out;
	}
}
