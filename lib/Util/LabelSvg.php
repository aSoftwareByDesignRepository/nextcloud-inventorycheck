<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Util;

use splitbrain\phpQRCode\QRCode;

/**
 * Printable item label: real QR of scan_code + selectable text alternative (SPEC A12).
 */
final class LabelSvg
{
	/**
	 * Build a standalone SVG label (QR + human-readable code + optional name).
	 */
	public static function forItem(string $scanCode, string $sku, string $name): string
	{
		$scanCode = trim($scanCode);
		if ($scanCode === '') {
			throw new \InvalidArgumentException('scan_code_required');
		}

		$qrSvg = QRCode::svg($scanCode, ['s' => 'qrm']);
		if (!preg_match('/viewBox="0 0 (\d+) (\d+)"/', $qrSvg, $m)) {
			throw new \RuntimeException('qr_svg_malformed');
		}
		$matrix = (int)$m[1];
		$inner = preg_replace('/^[\s\S]*?<svg[^>]*>/', '', $qrSvg) ?? '';
		$inner = preg_replace('/<\/svg>\s*$/', '', $inner) ?? '';

		$escCode = htmlspecialchars($scanCode, ENT_QUOTES | ENT_XML1, 'UTF-8');
		$escSku = htmlspecialchars($sku, ENT_QUOTES | ENT_XML1, 'UTF-8');
		$escName = htmlspecialchars(mb_substr($name, 0, 64), ENT_QUOTES | ENT_XML1, 'UTF-8');

		// Fit QR into a 200×200 box with quiet zone; leave room for text below.
		$target = 200.0;
		$scale = $target / max(1, $matrix);
		$tx = (320.0 - $target) / 2.0;
		$ty = 24.0;

		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<svg xmlns="http://www.w3.org/2000/svg" width="320" height="360" viewBox="0 0 320 360"'
			. ' role="img" aria-label="' . $escCode . '">'
			. '<title>' . $escCode . '</title>'
			. '<rect width="320" height="360" fill="#ffffff"/>'
			. '<g transform="translate(' . $tx . ' ' . $ty . ') scale(' . $scale . ')" fill="#111111">'
			. $inner
			. '</g>'
			. '<text x="160" y="250" text-anchor="middle" fill="#111111"'
			. ' font-family="DejaVu Sans, Liberation Sans, Arial, sans-serif" font-size="14">'
			. $escName
			. '</text>'
			. '<text x="160" y="278" text-anchor="middle" fill="#333333"'
			. ' font-family="DejaVu Sans Mono, Liberation Mono, monospace" font-size="13">'
			. 'SKU ' . $escSku
			. '</text>'
			. '<text id="iv-label-code" x="160" y="312" text-anchor="middle" fill="#111111"'
			. ' font-family="DejaVu Sans Mono, Liberation Mono, monospace" font-size="16" font-weight="700">'
			. $escCode
			. '</text>'
			. '<text x="160" y="340" text-anchor="middle" fill="#555555"'
			. ' font-family="DejaVu Sans, Liberation Sans, Arial, sans-serif" font-size="11">'
			. 'InventoryCheck'
			. '</text>'
			. '</svg>';
	}
}
