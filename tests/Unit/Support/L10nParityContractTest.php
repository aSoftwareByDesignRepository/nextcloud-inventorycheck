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
		self::assertArrayNotHasKey('Device pairing: coming soon', $enT);
		self::assertArrayNotHasKey('Device pairing: coming soon', $deT);
	}
}
