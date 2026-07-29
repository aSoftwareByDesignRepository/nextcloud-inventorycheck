<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Util;

/**
 * Code 128 Subset B → SVG bars for InventoryCheck labels.
 *
 * Payload is the item {@see scan_code} (same string as the QR). Charset is
 * Code 128 B (ASCII 32–126). InventoryCheck S7 codes are a strict subset.
 *
 * No third-party barcode dependency — keeps AGPL vendoring and audit surface small.
 */
final class Code128Svg
{
	private const START_B = 104;
	private const STOP = 106;

	/**
	 * Module-width patterns (bar/space alternating, starting with bar).
	 * Indices 0–102 are data; 103–105 start codes; 106 is stop (13 modules).
	 *
	 * @var list<string>
	 */
	private const PATTERNS = [
		'212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
		'221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
		'221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
		'212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
		'231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
		'231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
		'314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
		'112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
		'111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
		'214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
		'114131', '311141', '411131', '211412', '211214', '211232', '2331112',
	];

	/**
	 * Build an SVG fragment (group of rects) for Code 128 B of $payload.
	 *
	 * @throws \InvalidArgumentException empty or non–Code-128-B character
	 */
	public static function barsGroup(
		string $payload,
		float $x,
		float $y,
		float $width,
		float $height,
		string $id = 'iv-label-barcode',
	): string {
		$payload = trim($payload);
		if ($payload === '') {
			throw new \InvalidArgumentException('barcode_payload_required');
		}
		$values = self::symbolValues($payload);
		$modules = self::totalModules($values);
		$moduleW = $width / max(1, $modules);
		$escPayload = htmlspecialchars($payload, ENT_QUOTES | ENT_XML1, 'UTF-8');
		$escId = htmlspecialchars($id, ENT_QUOTES | ENT_XML1, 'UTF-8');

		$out = '<g id="' . $escId . '" role="img" data-symbology="code128b" data-payload="'
			. $escPayload . '" aria-label="Code 128 ' . $escPayload . '">';
		$cursor = $x;
		$drawBar = true;
		foreach ($values as $value) {
			$pattern = self::PATTERNS[$value];
			$len = strlen($pattern);
			for ($i = 0; $i < $len; $i++) {
				$w = ((int)$pattern[$i]) * $moduleW;
				if ($drawBar) {
					$out .= '<rect x="' . self::f($cursor) . '" y="' . self::f($y)
						. '" width="' . self::f($w) . '" height="' . self::f($height)
						. '" fill="#111111"/>';
				}
				$cursor += $w;
				$drawBar = !$drawBar;
			}
		}
		$out .= '</g>';
		return $out;
	}

	/**
	 * @return list<int> start + data + checksum + stop
	 */
	public static function symbolValues(string $payload): array
	{
		$values = [self::START_B];
		$len = strlen($payload);
		for ($i = 0; $i < $len; $i++) {
			$ord = ord($payload[$i]);
			if ($ord < 32 || $ord > 126) {
				throw new \InvalidArgumentException('barcode_charset');
			}
			$values[] = $ord - 32;
		}
		$values[] = self::checksum($values);
		$values[] = self::STOP;
		return $values;
	}

	/**
	 * Weighted mod-103 checksum over start + data symbols (ISO/IEC 15417).
	 *
	 * @param list<int> $startAndData
	 */
	public static function checksum(array $startAndData): int
	{
		if ($startAndData === []) {
			throw new \InvalidArgumentException('barcode_checksum_empty');
		}
		$sum = (int)$startAndData[0];
		$count = count($startAndData);
		for ($i = 1; $i < $count; $i++) {
			$sum += $i * (int)$startAndData[$i];
		}
		return $sum % 103;
	}

	/** @param list<int> $values */
	public static function totalModules(array $values): int
	{
		$total = 0;
		foreach ($values as $value) {
			$pattern = self::PATTERNS[$value] ?? null;
			if ($pattern === null) {
				throw new \InvalidArgumentException('barcode_pattern');
			}
			$len = strlen($pattern);
			for ($i = 0; $i < $len; $i++) {
				$total += (int)$pattern[$i];
			}
		}
		return $total;
	}

	private static function f(float $n): string
	{
		return rtrim(rtrim(sprintf('%.4F', $n), '0'), '.') ?: '0';
	}
}
