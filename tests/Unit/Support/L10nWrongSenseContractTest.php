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
			// nl/nb/da compound head-final nouns (Licentiestoelen, Mobilsetet)
			// slip past left-anchored \b — those legs run unanchored on purpose.
			'nl' => ['/stoel/iu', '/zitplaats/iu', '/\bzitting/iu'],
			'pl' => ['/\bfotel/iu', '/\bsiedzeni/iu', '/\bsiedzisk/iu'],
			'da' => ['/sæde[rtn]?\b/iu', '/\bstol(?:e|en|ene|er|erne)?\b/iu'],
			'nb' => ['/sete[rtn]?\b/iu', '/\bstol(?:e|en|ene|er|erne)?\b/iu'],
			'it' => ['/\bpostazion/iu', '/\bsedia|\bsedile/iu'],
			'es' => ['/\bpuestos?\b/iu', '/\basientos?\b/iu', '/\bsilla/iu'],
			'fr' => ['/\bsièg/iu', '/\bchaise/iu'],
			'pt' => ['/\bcadeira/iu'],
			'pt_BR' => ['/licença móvel/iu', '/\bcadeira/iu'],
		],
		// device slots: allocation slots — never slit/track/gambling senses
		// (fr "machines à sous", nb "enhetsspor", pl "szczelina" all shipped).
		'/\bslots?\b/iu' => [
			'fr' => ['/machine.? à sous/iu', '/fente/iu'],
			'es' => ['/\brango\b/iu'],
			'nb' => ['/spor(?:s|et|ene)?\b/iu'],
			'pl' => ['/szczelin/iu'],
			'nl' => ['/sleuf/iu'],
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
		// clipboard paste: substance senses (es pasta, fr pâte, da pastafeltet,
		// pl pola pasty), try-senses (es Pruebe), and untranslated EN Paste
		// leads all shipped pre-fix (iv-crit9).
		'/\bpaste\b/iu' => [
			'de' => ['/\bpaste\b/iu', '/\bKleister/iu', '/\bTeig\b/iu', '/\bprobier/iu', '/\bteste[rn]?\b/iu'],
			'es' => ['/\bpaste\b/iu', '/\bpasta\b/iu', '/prueb/iu', '/\bprobar\b/iu', '/\bprueba\b/iu'],
			'fr' => ['/\bpaste\b/iu', '/\bpâte\b/iu', '/\bessai/iu', '/\btester\b/iu'],
			'it' => ['/\bpaste\b/iu', '/\bpasta\b/iu', '/\bprova\b/iu', '/di incolla\b/iu'],
			'da' => ['/\bpaste\b/iu', '/\bpasta/iu', '/\bprøv/iu'],
			'nb' => ['/\bpaste\b/iu', '/\bpasta/iu', '/\bprøv/iu'],
			'nl' => ['/\bpaste\b/iu', '/\bpasta\b/iu', '/\bproef/iu'],
			'pl' => ['/\bpaste\b/iu', '/\bpast[ayąeę]/iu', '/\bprób/iu'],
			'pt' => ['/\bpaste\b/iu', '/\bmassa\b/iu', '/\bcola\b/iu', '/\bteste\b/iu'],
			'pt_BR' => ['/\bpaste\b/iu', '/\bmassa\b/iu', '/\bcola\b/iu', '/\bteste\b/iu'],
			'sv' => ['/\bpaste\b/iu', '/\bpasta/iu', '/\bprova\b/iu', '/\btesta\b/iu'],
		],
		// credential/licence key: keyboard-key senses (es tecla, fr touche,
		// pl klawisz, it tasto, nl toets, de Taste, da/nb tast, sv tangent,
		// pt tecla) and door-key (es llave) shipped pre-fix.
		'/\bkeys?\b/iu' => [
			'de' => ['/\bTaste[rn]?\b/iu'],
			'es' => ['/\btecla/iu', '/\bllave/iu'],
			'fr' => ['/\btouche/iu'],
			'it' => ['/\btasto/iu'],
			'da' => ['/\btast(?:e|en|er|erne)?\b/iu'],
			'nb' => ['/\btast(?:e|en|er|ene|erne)?\b/iu'],
			'nl' => ['/\btoets(?:e|en)?\b/iu'],
			'pl' => ['/\bklawisz/iu'],
			'pt' => ['/\btecla/iu'],
			'pt_BR' => ['/\btecla/iu'],
			'sv' => ['/\btangent/iu'],
		],
		// device pairing: couple/mating senses shipped (es pareja/pares,
		// es Pared=wall, fr paire, nl paar-code/paren=mate, pl Para noun /
		// kod pary / wspólnych prób / połączone, pt_BR Código de par).
		'/\bpair/iu' => [
			'es' => ['/\bpareja/iu', '/\bpares\b/iu', '/\bpared\b/iu', '/\bde par\b/iu', '/\bunión\b/iu'],
			'fr' => ['/\bpaire\b/iu', '/\bpairez\b/iu', '/\bpairer\b/iu'],
			'it' => ['/\bcoppia/iu'],
			'da' => ['/\buafslutt/iu'],
			'nb' => ['/\bpar kod/iu', '/\bkobl/iu'],
			'nl' => ['/\bpaar/iu', '/pareerd/iu', '/\bparen\b/iu'],
			'pl' => ['/\bpar[ayęe]\b/iu', '/\błączen/iu', '/wspólnych prób/iu', '/połączon/iu'],
			'pt' => ['/\bpar\b/iu'],
			'pt_BR' => ['/\bpar\b/iu'],
			'sv' => ['/\bpar\b/iu'],
			'de' => ['/\bpaar\b/iu', '/\bpaaren\b/iu'],
		],
		// scan code: es exploración (=exploration), nb Søkekode (=search
		// code), fr Code d'analyse / de balayage, pt_BR verb-form label.
		'/\bscan[_ ]?codes?\b/iu' => [
			'es' => ['/\bexploraci/iu', '/\bescaneado\b/iu'],
			'nb' => ['/\bsøke/iu'],
			'da' => ['/\bsøge/iu'],
			'fr' => ["/d'analyse\b/iu", '/\bbalayage/iu', '/et scan\b/iu'],
			'pt_BR' => ['/^Digitalizar/iu', '/\bescaneie\b/iu'],
		],
	];

	/**
	 * [key-family regex] => [locale => required canonical-term regex].
	 * Every key in the family must render the product's domain term —
	 * catches entity-name drift (nl voorraadopname/inventarisatie,
	 * da lager, es acción/carga, fr bilan/stock for the stocktake entity).
	 */
	private const REQUIRED = [
		// clipboard-paste canon per locale (iv-crit9 family)
		'/\bpaste\b/iu' => [
			'de' => 'einfüg|einsetz|tipp|füg\w*\b[^.]{0,60}\bein\b',
			'es' => 'peg',
			'fr' => 'coll',
			'it' => 'incoll',
			'da' => 'indsæt',
			'nb' => 'lim|innlim',
			'nl' => 'plak',
			'pl' => 'wklej',
			'pt' => 'col',
			'pt_BR' => 'col',
			'sv' => 'klistr|inklistr',
		],
		// credential-key canon per locale (iv-crit9 family)
		'/\bkeys?\b/iu' => [
			'de' => 'schlüssel',
			'es' => 'clave',
			'fr' => 'clé|clef',
			'it' => 'chiav',
			'da' => 'nøgle',
			'nb' => 'nøkkel',
			'nl' => 'sleutel',
			'pl' => 'klucz',
			'pt' => 'chav',
			'pt_BR' => 'chav',
			'sv' => 'nyckel',
		],
		// device-pairing canon — also catches dropped pairing terms
		'/\bpair/iu' => [
			'de' => 'kopp|pair',
			'es' => 'emparej|empár',
			'fr' => 'appari|appair|jumel|coupl|assoc',
			'it' => 'associ|abbin|accoppi',
			'da' => 'par',
			'nb' => 'par',
			'nl' => 'koppel',
			'pl' => 'paro|spar',
			'pt' => 'emparelh|parea|parei',
			'pt_BR' => 'emparelh|parea|parei',
			'sv' => 'parkoppl',
		],
		// scan-code canon per locale
		'/\bscan[_ ]?codes?\b/iu' => [
			'de' => 'scan',
			'es' => 'escaneo|escan',
			'fr' => 'numéris',
			'it' => 'scansion',
			'da' => 'scan|skann',
			'nb' => 'skann',
			'nl' => 'scan',
			'pl' => 'skanowan|skanując',
			'pt' => 'leitura|escan|digitaliz',
			'pt_BR' => 'digitaliz|escane|leitura',
			'sv' => 'skann',
		],
		'/\bseats?\b/iu' => [
			'de' => 'sitz|pl[äa]tz|lizenz',
			'da' => 'plads|licens',
			'es' => 'plaza|licencia',
			'fr' => 'poste|place|licence',
			'it' => 'posto|posti|posizion|postazion|licenza',
			'nb' => 'plass|lisens',
			'nl' => 'seat|plek|plaats|licentie',
			'pl' => 'miejsce|miejsc|stanowisk|licencj',
			'sv' => 'plats|licens',
			'pt' => 'lugar|licença|vaga',
			'pt_BR' => 'assento|licença|lugar',
		],
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
		'Mobile seats' => [
			'de' => 'sitz', 'da' => 'plads', 'es' => 'plaza', 'fr' => 'poste',
			'it' => 'post', 'nb' => 'plass', 'nl' => 'seat', 'pl' => 'stanowisk|miejsc',
			'sv' => 'plats', 'pt' => 'lugar', 'pt_BR' => 'assento',
		],
		'Seat assigned.' => [
			'de' => 'sitz', 'da' => 'plads', 'es' => 'plaza', 'fr' => 'poste',
			'it' => 'posto', 'nb' => 'plass', 'nl' => 'seat', 'pl' => 'miejsce|stanowisk',
			'sv' => 'plats', 'pt' => 'lugar', 'pt_BR' => 'assento',
		],
		// iv-crit9 sentinel cells — the exact cells the critic flagged
		'Paste IV2 key' => [
			'de' => 'schlüssel', 'da' => 'nøgle', 'es' => 'clave', 'fr' => 'clé',
			'it' => 'chiave', 'nb' => 'nøkkel', 'nl' => 'sleutel', 'pl' => 'klucz',
			'sv' => 'nyckel', 'pt' => 'chave', 'pt_BR' => 'chave',
		],
		'Paste CSV here, or choose a file above.' => [
			'de' => 'einfüg', 'da' => 'indsæt', 'es' => 'pegue', 'fr' => 'collez',
			'it' => 'incoll', 'nb' => 'lim inn', 'nl' => 'plak', 'pl' => 'wklej',
			'sv' => 'klistr', 'pt' => 'cole', 'pt_BR' => 'cole',
		],
		'Tip: wedge scanners type into the paste field — we resolve the item automatically.' => [
			'de' => 'einfügefeld', 'da' => 'indsætningsfelt', 'es' => 'campo de pegado',
			'fr' => 'champ de collage', 'it' => 'campo di incollaggio', 'nb' => 'innlimingsfelt',
			'nl' => 'plakveld', 'pl' => 'pola wklejania', 'sv' => 'inklistringsfält',
			'pt' => 'campo de colar', 'pt_BR' => 'campo de colar',
		],
		'Paired' => [
			'de' => 'gekoppelt', 'da' => 'parret', 'es' => 'emparejado',
			'fr' => 'couplé|jumelé|appairé', 'it' => 'associato', 'nb' => 'parret',
			'nl' => 'gekoppeld', 'pl' => 'sparowane', 'sv' => 'parkopplad',
			'pt' => 'emparelhado', 'pt_BR' => 'emparelhado',
		],
		'Expired pair code' => [
			'de' => 'pairing', 'da' => 'parkode', 'es' => 'emparejamiento',
			'fr' => 'appariement', 'it' => 'associazione', 'nb' => 'parkode',
			'nl' => 'koppelingscode', 'pl' => 'parowania', 'sv' => 'parkopplingskod',
			'pt' => 'emparelhamento', 'pt_BR' => 'pareamento|emparelhamento',
		],
		'Pending pair code' => [
			'de' => 'pairing', 'da' => 'parkode', 'es' => 'emparejamiento',
			'fr' => 'appariement', 'it' => 'associazione', 'nb' => 'parkode',
			'nl' => 'koppelingscode', 'pl' => 'parowania', 'sv' => 'parkopplingskod',
			'pt' => 'emparelhamento', 'pt_BR' => 'pareamento|emparelhamento',
		],
		'Scan code' => [
			'de' => 'scan-code', 'da' => 'scan', 'es' => 'escaneo', 'fr' => 'numérisation',
			'it' => 'scansione', 'nb' => 'skannekode', 'nl' => 'scancode', 'pl' => 'skanowania',
			'sv' => 'skanningskod', 'pt' => 'leitura', 'pt_BR' => 'digitalização',
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
			// CSV header lines stay literal English in every locale — the
			// glossary exempts them via unless_key, the contract skips here.
			if (str_starts_with((string)$key, 'Columns: ')) {
				continue;
			}
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
