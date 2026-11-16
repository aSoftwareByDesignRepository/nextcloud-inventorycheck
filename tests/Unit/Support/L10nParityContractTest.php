<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * N9 — every tr() key in app.js must exist in EN and DE catalogs.
 */
final class L10nParityContractTest extends TestCase
{
	public function testAppJsTrKeysExistInEnAndDe(): void
	{
		$root = dirname(__DIR__, 3);
		$js = (string)file_get_contents($root . '/js/app.js');
		preg_match_all("/\\btr\\('((?:\\\\'|[^'])*)'(?:\\s*,|\\))/", $js, $m);
		$keys = array_values(array_unique(array_map(
			static fn (string $k): string => str_replace("\\'", "'", $k),
			$m[1],
		)));
		self::assertNotEmpty($keys);

		$en = json_decode((string)file_get_contents($root . '/l10n/en.json'), true, 512, JSON_THROW_ON_ERROR);
		$de = json_decode((string)file_get_contents($root . '/l10n/de.json'), true, 512, JSON_THROW_ON_ERROR);
		$enT = $en['translations'] ?? [];
		$deT = $de['translations'] ?? [];

		$missingEn = [];
		$missingDe = [];
		foreach ($keys as $key) {
			if (!array_key_exists($key, $enT)) {
				$missingEn[] = $key;
			}
			if (!array_key_exists($key, $deT)) {
				$missingDe[] = $key;
			}
		}
		self::assertSame([], $missingEn, 'Missing EN keys: ' . implode(' | ', $missingEn));
		self::assertSame([], $missingDe, 'Missing DE keys: ' . implode(' | ', $missingDe));
		self::assertPluralPairsExist($enT, $deT);
		self::assertArrayNotHasKey('Device pairing: coming soon', $enT);
		self::assertArrayNotHasKey('Device pairing: coming soon', $deT);
		self::assertArrayNotHasKey('Native scanner app: coming soon. Device slots and pairing codes work now.', $enT);
		self::assertArrayNotHasKey('Native scanner app: coming soon. Device slots and pairing codes work now.', $deT);
	}

	/**
	 * Every nr()/n() plural call site must resolve a _sg_::_pl_ combined
	 * catalog key holding a two-form array (singular msgid carries the
	 * literal '1', plural carries %n — lazy_plural_paren class).
	 *
	 * @param array<string, mixed> $enT
	 * @param array<string, mixed> $deT
	 */
	private static function assertPluralPairsExist(array $enT, array $deT): void
	{
		$root = dirname(__DIR__, 3);
		$pairs = [];
		foreach (['js/app.js' => 'nr', 'js/transfer.js' => 'n'] as $file => $fn) {
			$js = (string)file_get_contents($root . '/' . $file);
			preg_match_all(
				'/\b' . $fn . "\\('((?:\\\\'|[^'])*)'\\s*,\\s*'((?:\\\\'|[^'])*)'\\s*,/",
				$js,
				$m,
			);
			foreach ($m[1] as $i => $sg) {
				$pairs[] = [
					str_replace("\\'", "'", $sg),
					str_replace("\\'", "'", $m[2][$i]),
					$file . ' ' . $fn . '()',
				];
			}
		}
		self::assertNotEmpty($pairs, 'no plural call sites found — extractor drifted');

		$missingEn = $missingDe = $badShape = [];
		foreach ($pairs as [$sg, $pl, $site]) {
			$key = '_' . $sg . '_::_' . $pl . '_';
			if (!array_key_exists($key, $enT)) {
				$missingEn[] = $site . ' ' . $key;
				continue;
			}
			if (!array_key_exists($key, $deT)) {
				$missingDe[] = $site . ' ' . $key;
			}
			if (!is_array($enT[$key]) || count($enT[$key]) !== 2) {
				$badShape[] = $key;
			}
		}
		self::assertSame([], $missingEn, 'Missing EN plural keys: ' . implode(' | ', $missingEn));
		self::assertSame([], $missingDe, 'Missing DE plural keys: ' . implode(' | ', $missingDe));
		self::assertSame([], $badShape, 'Plural keys without [sg, pl] array: ' . implode(' | ', $badShape));
	}
}
