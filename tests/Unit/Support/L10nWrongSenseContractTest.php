<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * l10n_wrong_sense_token — polyseme family contract.
 *
 * The generic real-lexicon gate cannot see "valid word, wrong sense"
 * (e.g. fr "Presque." for "Close anyway", nb "ubetalt" for uncounted,
 * fr "réservation" for a posted stock booking). This test pins:
 *
 *  1. FORBIDDEN — wrong-sense tokens per locale, scoped to the EN key
 *     family they poisoned (book/close/post/seat/kind/count/grant/
 *     reverse/stocktake/item/issue).
 *  2. SENTINELS — canonical-sense terms that must appear on the
 *     critic-flagged cells.
 *
 * Sense-2 (commercial "book" = commission/order) keys are exempt by key.
 */
final class L10nWrongSenseContractTest extends TestCase
{
	private const LOCALES = ['de', 'da', 'es', 'fr', 'it', 'nb', 'nl', 'pl', 'sv', 'pt', 'pt_BR'];

	/** Commercial "book" senses where reservation-class terms are legitimate. */
	private const SENSE2_KEYS = [
		'%s stays free (AGPL) on your Nextcloud. Bug reports and ideas on GitHub stay welcome — that is free open-source care. If your organisation needs bookable help on an invoice — or official mobile licenses — choose an option below:',
		'%s stays free (AGPL) on your Nextcloud. Bug reports and ideas on GitHub stay welcome — that is free open-source care. If your organisation needs bookable help on an invoice, choose an option below:',
		'Email is best-effort — no reply SLA. Need booked help? Use Support & us.',
		'Organisations can book prioritised features via the contact options below.',
	];

	/**
	 * [key-family regex] => [locale => [forbidden value regexes]]
	 */
	private const FORBIDDEN = [
		'/\bbook(?:s|ings?)?\b/i' => [
			'fr' => ['/\bréserv/iu', '/\blivres?\b/iu'],
			'es' => ['/\breserv/iu', '/\blibros?\b/iu'],
			'pt' => ['/\breserv/iu'],
			'pt_BR' => ['/\breserv/iu'],
			'nb' => ['/\bbestilling/iu', '/\bbook/iu'],
			'da' => ['/\breservation/iu', '/\bbestill/iu', '/\bbook/iu', '/\bbog\b(?!før)/iu'],
			'sv' => ['/bokning/iu', '/\bboka\b/iu', '/\bbokar\b/iu', '/\bbokas\b/iu'],
			'pl' => ['/\brezerw/iu', '/\bksiążk/iu'],
			'nl' => ['/boekenvoorraad/iu'],
		],
		'/\bclos/iu' => [
			'fr' => ['/\bpresque\b/iu', '/\bprès\b/iu'],
			'es' => ['/\bcerca\b/iu'],
			'pt' => ['/\bperto\b/iu'],
			'pt_BR' => ['/\bperto\b/iu', '/\bpostar\b/iu', '/\bpostagem/iu'],
			'pl' => ['/\bblisko\b/iu', '/\bzamknij się/iu'],
			'da' => ['/tæt på/iu'],
			'nl' => ['/\bbijna\b/iu'],
			'nb' => ['/\bnær\b/iu'],
		],
		'/\bpost/iu' => [
			'es' => ['/\bcorreo/iu', '/\bpuestos?\b/iu'],
			'fr' => ['/\bposte\b/iu', '/\bpostal/iu'],
			'pl' => ['/\bpoczt/iu'],
			'pt' => ['/\bpostar\b/iu'],
			'pt_BR' => ['/\bpostar\b/iu', '/\bpostagem/iu'],
			'da' => ['/postjustering/iu', '/\budstationeret/iu'],
			'nb' => ['/\binnlegg/iu', '/\butgitt\b/iu'],
		],
		'/\bseats?\b/iu' => [
			'nl' => ['/\bstoel/iu', '/\bzitplaats/iu', '/\bzitting\b/iu'],
			'pl' => ['/\bfotel/iu', '/\bsiedzeni/iu', '/\bsiedzisk/iu'],
			'da' => ['/\bsæde/iu'],
			'nb' => ['/\bseter?\b/iu'],
			'it' => ['/\bpostazion/iu'],
			'es' => ['/\bpuestos?\b/iu'],
			'pt_BR' => ['/licença móvel/iu'],
		],
		'/\bkinds?\b/iu' => [
			'nb' => ['/\bsnill\b/iu', '/\bvennlig/iu'],
			'fr' => ['/\bgentil/iu'],
			'es' => ['/\bamable\b/iu'],
			'da' => ['/\bslags\b/iu'],
		],
		'/\bcount/iu' => [
			'nb' => ['/\bubetalt/iu', '/\bregnet\b/iu', '/\bberegnet\b/iu', '/\btallmengd/iu'],
			'da' => ['/\bubetalt/iu', '/\bformand\b/iu', '/\bregistreret\b/iu'],
			'es' => ['/\bcontable\b/iu'],
			'fr' => ['/nombre total/iu'],
			'nl' => ['/^\s*aantal\s*$/iu'],
			'pt_BR' => ['/\bcontábil\b/iu'],
		],
		'/\bgrant/iu' => [
			'es' => ['/\bsubvenc/iu', '/\bsubsid/iu', '/\bbecas?\b/iu'],
			'fr' => ['/\bsubvention/iu'],
			'nl' => ['/\bsubsid/iu'],
			'pl' => ['/\bdotac/iu'],
			'pt_BR' => ['/\bsubsíd/iu'],
		],
		'/\brevers/iu' => [
			'nl' => ['/\bomgekeerd/iu'],
			'da' => ['/\bomvendt/iu', '/\bændrer\b/iu'],
			'es' => ['/\binversión/iu', '/\binviert|\binvert/iu'],
		],
		'/\bstocktake/iu' => [
			'es' => ['/\bacción\b/iu', '/\bcarga\b/iu'],
			'pl' => ['/\bzasób\b/iu', '/\bzasobow/iu'],
			'fr' => ['/\bstocktage/iu', '/\bbilan\b/iu', '/faire un stock/iu', '/\bstock ouvert\b/iu'],
		],
		'/\bitem/iu' => [
			'nl' => ['/\bpost\b/iu', '/\bpunt\b/iu'],
			'fr' => ['/\bposte\b/iu', '/\bobjets?\b/iu', '/point trouvé|ce point/iu'],
			'da' => ['/\bgenstand/iu', '/\bemnet?\b/iu', '/\bemner\b/iu', '/\bpunkt/iu', '/\bting\b/iu', '/\btingene\b/iu'],
			'es' => ['/\bobjet/iu'],
			'pl' => ['/\brzeczy?\b/iu', '/\brzeczą\b/iu', '/\brzeczami\b/iu', '/\bprzedmiot/iu'],
			'pt_BR' => ['/\bunidades\b/iu', '/^unid\s*$/iu'],
		],
		'/\bissue/iu' => [
			'sv' => ['/\bboka\b/iu', '/\bbokar\b/iu', '/\bbokas\b/iu', '/bokning/iu'],
			'es' => ['/\breserv/iu', '/\bcuestiones\b/iu'],
			'nl' => ['/\bkwesties\b/iu'],
		],
	];

	/**
	 * [key-family regex] => [locale => required canonical-term regex].
	 * Every key in the family must render the product's domain term —
	 * catches entity-name drift (nl voorraadopname/inventarisatie,
	 * da lager, es acción/carga, fr bilan/stock for the stocktake entity).
	 */
	private const REQUIRED = [
		'/\bstocktake/iu' => [
			'de' => 'inventur|zählung',
			'da' => 'lagertælling|cykeltælling',
			'es' => 'inventario',
			'fr' => 'inventaire|stocktake',
			'it' => 'inventari',
			'nb' => 'lagertelling',
			'nl' => 'voorraadtelling',
			'pl' => 'inwentaryzacj',
			'sv' => 'inventering',
			'pt' => 'inventário',
			'pt_BR' => 'inventário',
		],
	];

	/** Values on these keys must contain one of the locale's canonical terms. */
	private const SENTINELS = [
		'Close anyway' => [
			'de' => 'schließ', 'da' => 'luk', 'es' => 'cerrar', 'fr' => 'fermer',
			'it' => 'chiud', 'nb' => 'lukk', 'nl' => 'sluit', 'pl' => 'zamknij',
			'sv' => 'stäng', 'pt' => 'fech', 'pt_BR' => 'fech',
		],
		'Close anyway and leave uncounted items unchanged' => [
			'de' => 'schließ', 'da' => 'luk', 'es' => 'cerrar', 'fr' => 'fermer',
			'it' => 'chiud', 'nb' => 'lukk', 'nl' => 'sluit', 'pl' => 'zamknij',
			'sv' => 'stäng', 'pt' => 'fech', 'pt_BR' => 'fech',
		],
		'Close and leave uncounted' => [
			'de' => 'schließ', 'da' => 'luk', 'es' => 'cerrar', 'fr' => 'fermer',
			'it' => 'chiud', 'nb' => 'lukk', 'nl' => 'sluit', 'pl' => 'zamknij',
			'sv' => 'stäng', 'pt' => 'fech', 'pt_BR' => 'fech',
		],
		'Close stocktake' => [
			'de' => 'inventur', 'da' => 'lagertælling|optælling', 'es' => 'inventario',
			'fr' => 'inventaire|stocktake', 'it' => 'inventario', 'nb' => 'lagertelling',
			'nl' => 'voorraadtelling|inventarisatie', 'pl' => 'inwentaryzacj',
			'sv' => 'inventering', 'pt' => 'inventário', 'pt_BR' => 'inventário',
		],
		'3. Close to post adjustments' => [
			'de' => 'schließ|buch', 'da' => 'luk', 'es' => 'cerrar|cierre', 'fr' => 'fermer',
			'it' => 'chiud', 'nb' => 'lukk', 'nl' => 'sluit', 'pl' => 'zamknij',
			'sv' => 'stäng', 'pt' => 'fech', 'pt_BR' => 'fech',
		],
		'Kind' => [
			'de' => 'art', 'da' => 'type', 'es' => 'tipo', 'fr' => 'type',
			'it' => 'tipo', 'nb' => 'type', 'nl' => 'soort', 'pl' => 'rodzaj',
			'sv' => 'typ', 'pt' => 'tipo', 'pt_BR' => 'tipo',
		],
		'1. Book stock' => [
			'de' => 'buch', 'da' => 'bogfør|book', 'es' => 'registr', 'fr' => 'enregistr',
			'it' => 'registrar', 'nb' => 'bokfør', 'nl' => 'boek', 'pl' => 'księg',
			'sv' => 'bokför', 'pt' => 'regist', 'pt_BR' => 'lance|lanç',
		],
		'2. Let them book issues' => [
			'de' => 'buch', 'da' => 'bogfør|book', 'es' => 'registr', 'fr' => 'enregistr',
			'it' => 'registrar', 'nb' => 'bokfør', 'nl' => 'boek', 'pl' => 'księg',
			'sv' => 'bokför', 'pt' => 'regist', 'pt_BR' => 'lance|lanç',
		],
		'Reverse' => [
			'de' => 'stornier', 'da' => 'revers', 'es' => 'revert', 'fr' => 'invers|storn|annul',
			'it' => 'storn', 'nb' => 'revers|tilbakefør', 'nl' => 'storner|terugboek', 'pl' => 'cofn|storn|cofa',
			'sv' => 'ångra', 'pt' => 'anular', 'pt_BR' => 'revert|estorn',
		],
		'Counted quantity' => [
			'de' => 'gezähl', 'da' => 'talt|talte', 'es' => 'contad', 'fr' => 'compt',
			'it' => 'contat', 'nb' => 'talt|telte', 'nl' => 'geteld', 'pl' => 'policz',
			'sv' => 'räknad', 'pt' => 'contad', 'pt_BR' => 'contad',
		],
	];

	private static function loadTranslations(string $locale): array
	{
		$path = dirname(__DIR__, 3) . '/l10n/' . $locale . '.json';
		$cat = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		return $cat['translations'] ?? [];
	}

	public function testNoWrongSenseTokensInFamilyKeys(): void
	{
		$en = self::loadTranslations('en');
		$cats = [];
		foreach (self::LOCALES as $loc) {
			$cats[$loc] = self::loadTranslations($loc);
		}

		$violations = [];
		foreach ($en as $key => $enVal) {
			if (in_array($key, self::SENSE2_KEYS, true)) {
				continue;
			}
			foreach (self::FORBIDDEN as $keyPat => $perLoc) {
				if (!preg_match($keyPat, (string)$key)) {
					continue;
				}
				foreach ($perLoc as $loc => $patterns) {
					$val = $cats[$loc][$key] ?? null;
					if (!is_string($val)) {
						continue;
					}
					// fr "grand livre" / es "libro mayor" are correct ledger senses.
					if ($loc === 'fr' && str_contains($val, 'grand livre')) {
						continue;
					}
					if ($loc === 'es' && (str_contains($val, 'libro mayor') || str_contains($val, 'libro de movimientos'))) {
						continue;
					}
					foreach ($patterns as $pat) {
						if (preg_match($pat, $val)) {
							$violations[] = sprintf('%s | %s | %s => %s', $keyPat, $loc, $key, $val);
						}
					}
				}
			}
		}
		self::assertSame([], $violations, "Wrong-sense l10n tokens:\n" . implode("\n", $violations));
	}

	public function testCanonicalTermsRequiredInFamilyKeys(): void
	{
		$en = self::loadTranslations('en');
		$violations = [];
		foreach ($en as $key => $enVal) {
			foreach (self::REQUIRED as $keyPat => $perLoc) {
				if (!preg_match($keyPat, (string)$key)) {
					continue;
				}
				foreach ($perLoc as $loc => $termRegex) {
					$tr = self::loadTranslations($loc);
					$val = $tr[$key] ?? null;
					self::assertIsString($val, sprintf('Missing translation %s for key %s', $loc, $key));
					if (!preg_match('/' . $termRegex . '/iu', (string)$val)) {
						$violations[] = sprintf('%s | %s => %s (want /%s/)', $loc, $key, $val, $termRegex);
					}
				}
			}
		}
		self::assertSame([], $violations, "Missing canonical terms:\n" . implode("\n", $violations));
	}

	public function testSentinelCellsUseCanonicalSense(): void
	{
		$violations = [];
		foreach (self::SENTINELS as $key => $perLoc) {
			foreach ($perLoc as $loc => $termRegex) {
				$tr = self::loadTranslations($loc);
				$val = $tr[$key] ?? null;
				self::assertIsString($val, sprintf('Missing translation %s for key %s', $loc, $key));
				if (!preg_match('/' . $termRegex . '/iu', (string)$val)) {
					$violations[] = sprintf('%s | %s => %s (want /%s/)', $loc, $key, $val, $termRegex);
				}
			}
		}
		self::assertSame([], $violations, "Sentinel wrong-sense cells:\n" . implode("\n", $violations));
	}

	public function testCsvColumnHeadersStayLiteral(): void
	{
		foreach (self::LOCALES as $loc) {
			$tr = self::loadTranslations($loc);
			foreach ($tr as $key => $val) {
				if (!str_starts_with($key, 'Columns: ')) {
					continue;
				}
				self::assertStringContainsString('sku', (string)$val, $loc . ' CSV header lost sku');
				self::assertStringContainsString('scan_code', (string)$val, $loc . ' CSV header lost scan_code');
				self::assertStringContainsString('reorder_level', (string)$val, $loc . ' CSV header lost reorder_level');
			}
		}
	}
}
