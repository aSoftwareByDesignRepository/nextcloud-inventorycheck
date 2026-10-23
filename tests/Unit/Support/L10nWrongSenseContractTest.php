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
		// iv-crit10 family — reorder = restock threshold, never re-sequence.
		// es Reorden / fr Réorganiser / nl Herschikken / pl Zmiana kolejności
		// / pt_BR Reordenar all shipped pre-fix as order-shuffle senses.
		'/\breorder/iu' => [
			'*' => ['/\breorders?\b/iu', '/\breordering\b/iu', '/\breordered\b/iu'],
			'es' => ['/\breorden/iu', '/\breorganiz/iu'],
			'fr' => ['/\bréordre/iu', '/\bréorganis/iu', '/recommand/iu'],
			'nl' => ['/\bherschik/iu'],
			'da' => ['/\bomsort/iu', '/\bomord/iu', '/rækkefølge/iu'],
			'pl' => ['/kolejności/iu', '/rzędu/iu'],
			'pt' => ['/\breorden/iu', '/\breorganiz/iu'],
			'pt_BR' => ['/\breorden/iu', '/\breorganiz/iu', '/\brecompr/iu'],
			'nb' => ['/\bomordn/iu', '/\bomorganis/iu'],
			'sv' => ['/\bomordn/iu', '/\bomorganis/iu'],
			'de' => ['/\bneuordn/iu', '/\bumordn/iu', '/\bumsort/iu', '/\bneusort/iu'],
			'it' => ['/\briorganis/iu'],
		],
		// iv-crit10 family — stock balance = on-hand level, never financial
		// balance/scale/leftover senses (fr balance, nl balans, de Waage).
		'/\bbalances?\b/iu' => [
			'*' => ['/\bbalances?\b/iu'],
			'es' => ['/\bbalanz/iu', '/\bequilibr/iu'],
			'fr' => ['/\bbalance/iu'],
			'nl' => ['/\bbalans/iu'],
			'da' => ['/\bbalanc/iu'],
			'nb' => ['/\bbalans/iu'],
			'sv' => ['/\bbalans/iu'],
			'de' => ['/\bbalance\b/iu', '/\bwaag/iu', '/gleichgewicht/iu'],
			'it' => ['/\bbilanci/iu', '/\bequilibr/iu'],
			'pl' => ['/\bwag[ai]\b/iu', '/\br[oó]wnowag/iu'],
			'pt' => ['/\bbalanç/iu', '/equil[ií]br/iu'],
			'pt_BR' => ['/\bbalanç/iu', '/equil[ií]br/iu'],
		],
		// iv-crit10 family — issue stock = withdraw stock, never emission/
		// edition/publication senses (nl aandelen = shares).
		'/\bstock\b/iu' => [
			'*' => ['/\bstock (updated|adjusted|issued|received|transferred)\b/iu'],
			'nl' => ['/\baandelen\b/iu'],
			'pl' => ['/\bakcj/iu'],
			'es' => ['/\bacciones?\b/iu', '/\bvalores\b/iu'],
			'fr' => ['/\bactions\b/iu'],
			'it' => ['/\bazion/iu'],
			'pt' => ['/\ba[çc][õo]es\b/iu'],
			'pt_BR' => ['/\ba[çc][õo]es\b/iu'],
			'da' => ['/\baktier/iu', '/\bstock\b/iu'],
			'nb' => ['/\baksj/iu'],
			'sv' => ['/\baktier/iu'],
			'de' => ['/\baktien/iu'],
		],
		// van = delivery vehicle, never chairperson/car/bus.
		'/\bvans?\b/iu' => [
			'da' => ['/\bformand/iu', '/\bvogn\b/iu'],
			'fr' => ['/\bvoiture\b/iu'],
			'nl' => ['/\bbus/iu'],
			'nb' => ['/\bwarehouse\b/iu'],
			'pl' => ['/\bbus\b/iu', '/\bbusa\b/iu', '/\bbusem\b/iu'],
		],
		// warehouse = storage building — da Huse (houses) shipped pre-fix.
		'/\bwarehouses?\b/iu' => [
			'*' => ['/\bwarehouses?\b/iu'],
			'da' => ['/\bhuse\b/iu'],
		],
		// ledger = movement book — nb ledning (wire), leder (leader),
		// haug (heap) shipped pre-fix.
		'/\bledger\b/iu' => [
			'*' => ['/\bledgers?\b/iu'],
			'nb' => ['/ledning/iu', '/leder\b/iu', '/haug/iu'],
		],
		// decimal places = digits after the separator, never locations.
		'/\bdecimal places?\b/iu' => [
			'es' => ['/lugares/iu', '/sitios/iu', '/espacios/iu'],
			'nb' => ['/\bsteder\b/iu'],
			'da' => ['/\bsteder\b/iu'],
			'fr' => ['/endroits/iu', '/lieux/iu'],
			'nl' => ['/plekken/iu', '/plaatsen/iu'],
			'it' => ['/\bposti\b/iu', '/\bluoghi\b/iu'],
			'sv' => ['/st[äa]llen/iu', '/\bplatser\b/iu'],
			'de' => ['/\borte\b/iu', '/\bpl[äa]tze\b/iu'],
		],
		// snapshot = saved system state, never photograph/interruption.
		'/\bsnapshot/iu' => [
			'es' => ['/\binterrump/iu'],
			'fr' => ['/\bphotograph/iu'],
			'nl' => ['/\bfotograf/iu', '/\bfoto\b/iu'],
			'pl' => ['/\bsprawdzi/iu'],
			'da' => ['/\boptog\b/iu', '/\bfotograf/iu'],
			'nb' => ['/\bser p[åa]/iu', '/\bfotograf/iu'],
			'de' => ['/\bfotograf/iu'],
			'it' => ['/\bfotograf/iu'],
			'pt' => ['/\bfotograf/iu'],
			'pt_BR' => ['/\bfotograf/iu'],
			'sv' => ['/\bfotograf/iu'],
		],
		// EN source typo "inventur" bred inventor-sense translations
		// (nl uitvinder, pl wynalazku, fr inventeur, da opfinder).
		'/\binventur\b/iu' => [
			'es' => ['/\binventur\b/iu', '/\binventor/iu'],
			'fr' => ['/\binventur\b/iu', '/inventeur/iu'],
			'it' => ['/\binventur\b/iu', '/inventore/iu'],
			'nl' => ['/\binventur\b/iu', '/uitvinder/iu'],
			'pl' => ['/\binventur\b/iu', '/wynalaz/iu', '/inwentor/iu'],
			'pt' => ['/\binventur\b/iu', '/inventor/iu'],
			'pt_BR' => ['/\binventur\b/iu', '/inven[çc]/iu', '/inventor/iu'],
			'da' => ['/\binventur\b/iu', '/opfind/iu'],
			'nb' => ['/\binventur\b/iu', '/oppfinn/iu'],
			'sv' => ['/\binventur\b/iu', '/uppfinn/iu'],
		],
		// count = tally items, never earl/account/rely-on senses
		// (es Conde/cuentas, fr comptes, da Tælleren, nb greve).
		'/\bcount/iu' => [
			'es' => ['/\bconde\b/iu', '/\bcondado/iu', '/cuent[ae] con\b/iu', '/cuente con\b/iu', '/\bcuentas\b/iu'],
			'fr' => ['/\bcomte\b/iu', '/\bcomt[ée]\b/iu', '/\bcomptes?\b/iu'],
			'nl' => ['/\bgraaf\b/iu', '/graafschap/iu', '/reken\w* op\b/iu'],
			'de' => ['/\bgraf\b/iu', '/z[äa]hl\w* auf (dich|sie|uns|euch|mich|ihn|ihnen|das)\b/iu'],
			'it' => ['/\bconte\b/iu', '/\bcontea/iu', '/cont\w* su\b/iu'],
			'pl' => ['/\bhrab/iu', '/licz\w* na (ciebie|niego|ni[ąa]|nie|nas|was|mnie|mn[ąa]|to)\b/iu'],
			'pt' => ['/\bconde\b/iu', '/cont\w* com\b/iu'],
			'pt_BR' => ['/\bconde\b/iu', '/cont\w* com\b/iu'],
			'da' => ['/\bgrev/iu', '/\bredd/iu', '/regner med/iu', '/stol p[åa]/iu'],
			'nb' => ['/\bgrev/iu', '/\bredd/iu', '/re[kg]ner med/iu', '/\bspar/iu'],
			'sv' => ['/\bgreve/iu', '/lita p[åa]/iu', '/r[äa]kn\w* med\b/iu'],
		],
		// Transfer in / Transfer out must stay directional — several locales
		// collapsed both to one bare word (Transferencia, Overdracht).
		'/\btransfer (in|out)\b/iu' => [
			'es' => ['/^transferir$/iu'],
			'pl' => ['/^przeniesienie$/iu'],
			'nl' => ['/^overdracht$/iu'],
			'fr' => ['/^transfert$/iu'],
			'da' => ['/^overf[øo]rsel$/iu'],
			'nb' => ['/^overf[øo]r$/iu'],
			'sv' => ['/^f[öo]rflyttning$/iu'],
			'de' => ['/^transfer\b/iu', '/^übertrag/iu'],
			'pt' => ['/^transferir$/iu'],
			'pt_BR' => ['/^transferir$/iu'],
			'it' => ['/^trasferimento$/iu'],
		],
		// match = same-as result, never matchstick/game senses
		// (nl lucifers, pl zapałki/mecz, es cerillas/partido).
		'/\bmatch/iu' => [
			'nl' => ['/\blucifer/iu', '/\bwedstrijd/iu'],
			'pl' => ['/zapa[łl]/iu', '/\bmecz/iu'],
			'es' => ['/\bcerilla/iu', '/f[óo]sforo/iu', '/\bpartido\b/iu'],
			'fr' => ['/\ballumette/iu', '/\bmatch\b/iu'],
			'de' => ['/streichholz/iu'],
			'it' => ['/fiammifer/iu', '/\bincontro\b/iu'],
			'pt' => ['/f[óo]sforo/iu', '/\bpartida\b/iu'],
			'pt_BR' => ['/f[óo]sforo/iu', '/\bpartida\b/iu'],
			'da' => ['/t[æa]ndstik/iu', '/\bkamp\b/iu'],
			'nb' => ['/fyrstikk/iu', '/\bkamp\b/iu'],
			'sv' => ['/t[äa]ndsticka/iu'],
		],
		// fractional = partial quantity, never broken pieces (pl połamane).
		'/\bfractional/iu' => [
			'pl' => ['/po[łl]aman/iu', '/[łl]aman/iu'],
			'es' => ['/\brot[oa]s?\b/iu', '/quebrad/iu'],
			'fr' => ['/cass[ée]/iu', '/bris[ée]/iu'],
			'it' => ['/\brot[ot]/iu'],
			'nl' => ['/gebroken/iu', '/gekneusd/iu'],
			'de' => ['/gebrochen/iu', '/zerbrochen/iu'],
			'da' => ['/\bbrudt/iu', '/kn[æa]k/iu'],
			'nb' => ['/\bbrutt/iu'],
			'sv' => ['/\bbrut/iu'],
			'pt' => ['/quebrad/iu'],
			'pt_BR' => ['/quebrad/iu'],
		],
		// append-only = add at the end, never appendix/annex body parts.
		'/\bappend/iu' => [
			'*' => ['/\bappendices\b/iu'],
			'es' => ['/ap[ée]ndice/iu'],
			'fr' => ['/\bannexe\b/iu'],
			'it' => ['/appendice/iu'],
			'de' => ['/\banhang\b/iu'],
			'pl' => ['/\bappend/iu'],
			'nl' => ['/\bbijlage/iu'],
			'pt' => ['/ap[êe]ndice/iu'],
			'pt_BR' => ['/ap[êe]ndice/iu'],
			'da' => ['/appendiks/iu', '/\bbilag\b/iu'],
			'nb' => ['/appendiks/iu', '/\bvedlegg/iu'],
			'sv' => ['/appendix/iu', '/\bbilaga/iu'],
		],
		// site = physical place, never web page (es página, pl strona).
		'/\bsite\b/iu' => [
			'es' => ['/\bp[áa]gina/iu', '/\bweb\b/iu'],
			'pl' => ['/\bstron/iu'],
			'nb' => ['/\bsite\b/iu'],
			'da' => ['/\bsite\b/iu'],
			'nl' => ['/\bsite\b/iu'],
			'de' => ['/\bsite\b/iu'],
		],
		// shelf = storage board, never shelf-life/shield/board senses
		// (pl Okres ważności, nb Skjold, da bræt all shipped pre-fix).
		'/\bshel/iu' => [
			'*' => ['/\bshelf\b/iu', '/\bshelves\b/iu'],
			'pl' => ['/wa[żz]no[śs]ci/iu'],
			'nb' => ['/\bskjold\b/iu'],
			'da' => ['/\bsk[æa]rm\b/iu', '/\bbr[æa]t\b/iu'],
			'de' => ['/\bbrett\b/iu'],
			'es' => ['/vida [úu]til/iu', '/\bplazo\b/iu'],
			'fr' => ['/dur[ée]e de vie/iu', '/p[ée]remption/iu'],
			'it' => ['/scadenz/iu'],
			'nl' => ['/houdbaarheid/iu'],
			'pt' => ['/validade/iu'],
			'pt_BR' => ['/validade/iu'],
			'sv' => ['/h[åa]llbarhet/iu', '/\bsk[öo]ld\b/iu'],
		],
		// bug = software defect, never insect (pl Robaki = worms shipped).
		'/\bbugs?\b/iu' => [
			'pl' => ['/\brobak/iu', '/\bowad/iu', '/\binsekt/iu'],
			'es' => ['/\binsecto/iu', '/\bbicho\b/iu'],
			'de' => ['/k[äa]fer/iu'],
			'fr' => ['/\binsecte/iu'],
			'it' => ['/\binsetto/iu'],
			'nl' => ['/\binsect/iu', '/\bkever/iu', '/\bwants/iu'],
			'pt' => ['/\binseto/iu', '/\bbicho/iu'],
			'pt_BR' => ['/\binseto/iu', '/\bbicho/iu'],
			'da' => ['/\binsekt/iu', '/\bbille/iu', '/v[æa]ggedyr/iu'],
			'nb' => ['/\binsekt/iu'],
			'sv' => ['/\binsekt/iu', '/b[äa]cklus/iu'],
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
			'pl' => 'inwentaryz',
			'sv' => 'inventering',
			'pt' => 'inventário',
			'pt_BR' => 'inventário',
		],
		// iv-crit10 canon — reorder = restock threshold terms only.
		'/\breorder/iu' => [
			'de' => 'mindestbestand|nachbestell|bestellpunkt|meldebestand',
			'es' => 'reposici|repon|punto de pedido|nivel de pedido|stock mínimo|pedido mínimo',
			'fr' => 'réappro|réassort|seuil de commande|point de commande|stock minimum',
			'it' => 'riordino|riordine|scorta minima|punto di riordino',
			'nl' => 'bestel|nabestell',
			'pl' => 'zam[oó]w|stan minimalny',
			'pt' => 'reposi|repor',
			'pt_BR' => 'reposi|repor|ponto de pedido|reabastec',
			'da' => 'genbestil|bestillingspunkt',
			'nb' => 'gjenbestil',
			'sv' => 'beställ',
		],
		// iv-crit10 canon — stock balance = on-hand level terms.
		'/\bbalances?\b/iu' => [
			'de' => 'best[äa]nd|saldo',
			'es' => 'saldo',
			'fr' => 'solde',
			'it' => 'sald|giacen',
			'nl' => 'sald',
			'pl' => 'sald|stan',
			'pt' => 'sald',
			'pt_BR' => 'sald',
			'da' => 'sald|beholdn',
			'nb' => 'sald|beholdn',
			'sv' => 'sald',
		],
		// iv-crit10 canon — issue stock = withdraw-stock verbs per locale.
		'/\bissue/iu' => [
			'de' => 'abg|ausg|ausz|abbuc|abzubuc|ausbuc|\baus\b',
			'es' => 'salid',
			'fr' => 'sort',
			'it' => 'uscit|prelev',
			'nl' => 'uitg|afgif|\buit\b',
			'pl' => 'wydani|wydan|wydaj|wyda|wydaw',
			'pt' => 'sa[íi]d',
			'pt_BR' => 'sa[íi]d|baix',
			'da' => 'udtag|udtage',
			'nb' => 'uttak|utta|utlev',
			'sv' => 'uttag|utl[äa]m|utlev',
		],
		// iv-crit10 canon — stock = inventory nouns, never share/stock phrases.
		'/\bstock\b/iu' => [
			'de' => 'bestand|best[äa]nd|lager|zugang|abgang|waren',
			'es' => 'stock|existencia|almacen',
			'fr' => 'stock',
			'it' => 'giacenz|scorte|stock|magazzin|uscit|entr|moviment|merce',
			'nl' => 'voorraad',
			'pl' => 'zapas|stan|magazyn',
			'pt' => 'stock',
			'pt_BR' => 'estoque|dep[oó]sito',
			'da' => 'lager|beholdn|bestand',
			'nb' => 'lager|beholdn',
			'sv' => 'lag[e]?r|sald|uttag|inleverans|inventer|r[öo]rels|mottag',
		],
		'/\bvans?\b/iu' => [
			'de' => 'transporter|fahrzeug|lieferwagen|van',
			'es' => 'furgoneta|camioneta|van',
			'fr' => 'fourgon|camionnette|utilitaire',
			'it' => 'furgon|van',
			'nl' => 'bestel|van|bedrijfs',
			'pl' => 'furgonet|dostawcz|busy|van',
			'pt' => 'carrinha|van',
			'pt_BR' => 'van|furg',
			'da' => 'varevogn|van',
			'nb' => 'varebil|van',
			'sv' => 'sk[åa]pbil|van',
		],
		'/\bwarehouses?\b/iu' => [
			'de' => 'lager',
			'es' => 'almac[eé]n',
			'fr' => 'entrep[ôo]t',
			'it' => 'magazzin',
			'nl' => 'pakhuis|magazijn',
			'pl' => 'magazyn',
			'pt' => 'armaz',
			'pt_BR' => 'armaz|dep[oó]sito',
			'da' => 'lager|lagre',
			'nb' => 'lag[e]?r[e]?|varehus',
			'sv' => 'lager',
		],
		'/\bledger\b/iu' => [
			'de' => 'journal|buch|hauptbuch|ledger',
			'es' => 'libro|registro|mayor|movimient',
			'fr' => 'registre|livre|journal',
			'it' => 'registro|libro',
			'nl' => 'grootboek|journaal|ledger',
			'pl' => 'rejestr|ksi[ęe]g|ksi[ąa][żz]',
			'pt' => 'livro|registo|moviment',
			'pt_BR' => 'livro|raz[ãa]o',
			'da' => 'hovedbog|regnskab|journal',
			'nb' => 'hovedbok|regnskap|journal',
			'sv' => 'huvudbok|journal',
		],
		'/\bdecimal places?\b/iu' => [
			'de' => 'dezimal|kommastell',
			'es' => 'decimal',
			'fr' => 'd[ée]cimal',
			'it' => 'decimal',
			'nl' => 'decima',
			'pl' => 'dziesi[ęe]tn|przecink',
			'pt' => 'decimal|decimais',
			'pt_BR' => 'decimal|decimais',
			'da' => 'decimal',
			'nb' => 'desimal',
			'sv' => 'decimal',
		],
		'/\bsnapshot/iu' => [
			'de' => 'snapshot|momentaufnahm|speicher|erfass',
			'es' => 'instant[áa]ne|captur|snapshot',
			'fr' => 'instantan[ée]|capture|snapshot',
			'it' => 'istantane|acquis|snapshot',
			'nl' => 'snapshot|momentopnam|opnam',
			'pl' => 'migawk',
			'pt' => 'instant[âa]ne|captur|tiramos',
			'pt_BR' => 'instant[âa]ne|captur|tiramos|snapshot',
			'da' => 'snapshot|[øo]jebliksbillede|optag',
			'nb' => '[øo]yeblikksbilde|snapshot',
			'sv' => '[öo]gonblicksbild|snapshot',
		],
		'/\binventur\b/iu' => [
			'es' => 'inventari',
			'fr' => 'inventaire',
			'it' => 'inventari',
			'nl' => 'voorraadtelling|inventaris|telling',
			'pl' => 'inwentaryz|inwentarz',
			'pt' => 'invent[áa]ri',
			'pt_BR' => 'invent[áa]ri',
			'da' => 'lagert[æa]lling|opt[æa]lling|t[æa]lling|inventar',
			'nb' => 'lagertelling|telling',
			'sv' => 'inventering',
		],
		'/\bcount/iu' => [
			'de' => 'z[äa]hl|inventur',
			'es' => 'cont|cuent',
			'fr' => 'compt',
			'it' => 'cont|contegg',
			'nl' => 'tel',
			'pl' => 'licz|policz',
			'pt' => 'cont',
			'pt_BR' => 'cont',
			'da' => 't[æa]l|talte|opt[æa]ll',
			'nb' => 'tell|talt|telte',
			'sv' => 'r[äa]kn',
		],
		'/\btransfer (in|out)\b/iu' => [
			'de' => 'zu|ab|ein|aus|umbuch',
			'es' => 'entrada|salida',
			'fr' => 'entrant|sortant',
			'it' => 'ingresso|uscita|entrata',
			'nl' => 'in\b|uit\b|inkom|uitga',
			'pl' => '\bdo\b|\bz\b|przych|rozch',
			'pt' => 'entrada|sa[íi]da',
			'pt_BR' => 'entrada|sa[íi]da',
			'da' => '\bind\b|\bud\b',
			'nb' => '\binn\b|\but\b',
			'sv' => '\bin\b|\but\b',
		],
		'/\bmatch/iu' => [
			'de' => 'treffer|gefund|pass|stimm|entspr',
			'es' => 'coincid|correspond',
			'fr' => 'correspond',
			'it' => 'corrispond|trova',
			'nl' => 'overeen|match|treff|gevond|passend|bijpass|past',
			'pl' => 'dopasow|pasuj|znalez|wynik|odpowiad|zgod',
			'pt' => 'correspond|encontr',
			'pt_BR' => 'correspond|encontr',
			'da' => 'match|hit|fundet|pass|resultat',
			'nb' => 'samsvar|match|treff|funn|pass|stemm',
			'sv' => 'match|tr[äa]ff|pass',
		],
		'/\bfractional/iu' => [
			'de' => 'bruch|dezimal|komma',
			'es' => 'fraccion|decimal',
			'fr' => 'fraction|d[ée]cimal',
			'it' => 'frazion|decimal',
			'nl' => 'fraction|decima',
			'pl' => 'u[łl]am|dziesi[ęe]tn|cz[ęe][śs]c',
			'pt' => 'fracion|decimal',
			'pt_BR' => 'fracion|decimal',
			'da' => 'fraktion|decimal|br[øo]k|komma',
			'nb' => 'fraksjon|desimal|br[øo]k',
			'sv' => 'decimal|br[åa]k|fraktion',
		],
		'/\bappend/iu' => [
			'de' => 'anh[äa]ng|anf[üu]g|anh[äa]ngbar',
			'es' => 'adici|añad|agreg|solo',
			'fr' => 'ajout',
			'it' => 'aggiunt|append',
			'nl' => 'toevoeg|aanvull|append',
			'pl' => 'dopis',
			'pt' => 'adi[çc]|acr[ée]sc|adicion|apens',
			'pt_BR' => 'acr[ée]sc|adi[çc]|adicion',
			'da' => 'tilf[øo]j',
			'nb' => 'tilf[øo]y',
			'sv' => 'till[äa]gg',
		],
		'/\bsite\b/iu' => [
			'de' => 'baustelle|standort|stelle',
			'es' => 'sitio|obra|lugar',
			'fr' => 'chantier|site',
			'it' => 'sede|cantiere|sito',
			'nl' => 'terrein|locatie|plaats',
			'pl' => 'budow|teren|plac|lokalizacj',
			'pt' => 'obra|loca|s[ií]tio',
			'pt_BR' => 'loca|obra',
			'da' => 'sted|byggeplads|plads',
			'nb' => 'byggeplass|sted|plass',
			'sv' => 'plats|arbetsplats',
		],
		'/\bshel/iu' => [
			'de' => 'regal',
			'es' => 'estant',
			'fr' => '[ée]tag[èe]re|rayon',
			'it' => 'scaffal',
			'nl' => 'plank|schap',
			'pl' => 'p[óo][łl][kc]',
			'pt' => 'prateleira',
			'pt_BR' => 'prateleira',
			'da' => 'hyld',
			'nb' => 'hyll',
			'sv' => 'hyll',
		],
		'/\bbugs?\b/iu' => [
			'de' => 'fehler',
			'es' => 'error',
			'fr' => 'bogue|erreur|bug',
			'it' => 'bug|errore',
			'nl' => 'bug|fout',
			'pl' => 'b[łl][ąaę]d',
			'pt' => 'erro|bug',
			'pt_BR' => 'bug|erro',
			'da' => 'fejl',
			'nb' => 'feil',
			'sv' => 'fel|bugg',
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
			'nl' => 'voorraadtelling|inventarisatie', 'pl' => 'inwentaryz',
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
		// iv-crit10 sentinel cells — reorder/issue/balance wrong-sense class.
		'Reorder' => [
			'de' => 'mindestbestand|nachbestell', 'da' => 'genbestil', 'es' => 'repon|reposici',
			'fr' => 'réappro', 'it' => 'riordino|riordine', 'nb' => 'gjenbestil',
			'nl' => 'bestel|nabestell', 'pl' => 'zam[oó]w', 'sv' => 'beställ',
			'pt' => 'reposi|repor', 'pt_BR' => 'reposi|repor',
		],
		'Issue stock' => [
			'de' => 'abgang|ausg', 'da' => 'udtag', 'es' => 'salida',
			'fr' => 'sortie', 'it' => 'uscita', 'nb' => 'uttak',
			'nl' => 'uitgeven|uitgifte', 'pl' => 'wydan|wyda', 'sv' => 'uttag',
			'pt' => 'saída', 'pt_BR' => 'saída',
		],
		'Stock issued.' => [
			'de' => 'abgang|bestand', 'da' => 'lager', 'es' => 'salida|stock',
			'fr' => 'stock|sortie', 'it' => 'uscita|giacenza', 'nb' => 'lager|uttak',
			'nl' => 'voorraad', 'pl' => 'zapas|wydan', 'sv' => 'uttag|lager',
			'pt' => 'stock|saída', 'pt_BR' => 'estoque|saída',
		],
		'Balances at this location' => [
			'de' => 'best[äa]nd|saldo', 'da' => 'sald|beholdn', 'es' => 'saldo',
			'fr' => 'solde', 'it' => 'sald|giacen', 'nb' => 'sald|beholdn',
			'nl' => 'sald', 'pl' => 'sald|stan', 'sv' => 'sald',
			'pt' => 'sald', 'pt_BR' => 'sald',
		],
		'Balances by location' => [
			'de' => 'best[äa]nd|saldo', 'da' => 'sald|beholdn', 'es' => 'saldo',
			'fr' => 'solde', 'it' => 'sald|giacen', 'nb' => 'sald|beholdn',
			'nl' => 'sald', 'pl' => 'sald|stan', 'sv' => 'sald',
			'pt' => 'sald', 'pt_BR' => 'sald',
		],
		'Count saved.' => [
			'de' => 'z[äa]hl', 'da' => 't[æa]l', 'es' => 'cont|cuent',
			'fr' => 'compt', 'it' => 'cont|contegg', 'nb' => 'tell|talt',
			'nl' => 'tel', 'pl' => 'licz|policz', 'sv' => 'r[äa]kn',
			'pt' => 'cont', 'pt_BR' => 'cont',
		],
		'Transfer in' => [
			'de' => 'zu|ein', 'da' => 'ind', 'es' => 'entrada',
			'fr' => 'entrant', 'it' => 'ingresso|entrata', 'nb' => 'inn',
			'nl' => 'in|inkom', 'pl' => 'przych', 'sv' => 'in',
			'pt' => 'entrada', 'pt_BR' => 'entrada',
		],
		'Transfer out' => [
			'de' => 'ab|aus', 'da' => 'ud', 'es' => 'salida',
			'fr' => 'sortant', 'it' => 'uscita', 'nb' => 'ut',
			'nl' => 'uit|uitga', 'pl' => 'rozch', 'sv' => 'ut',
			'pt' => 'saída', 'pt_BR' => 'saída',
		],
		'Shelf' => [
			'de' => 'regal', 'da' => 'hyld', 'es' => 'estant',
			'fr' => 'étagère|rayon', 'it' => 'scaffal', 'nb' => 'hyll',
			'nl' => 'plank|schap', 'pl' => 'półk', 'sv' => 'hyll',
			'pt' => 'prateleira', 'pt_BR' => 'prateleira',
		],
		'Warehouse, van, site' => [
			'de' => 'lager|transporter', 'da' => 'lager|varevogn', 'es' => 'almac|furgoneta',
			'fr' => 'entrepôt|camionnette', 'it' => 'magazzin|furgon', 'nb' => 'lager|varebil',
			'nl' => 'magazijn|bestel', 'pl' => 'magazyn|furgonet', 'sv' => 'lager|skåpbil',
			'pt' => 'armaz|carrinha', 'pt_BR' => 'armaz|van|furg',
		],
	];

	/**
	 * Family-level unless_key mirrors — keys matching the skip regex are
	 * exempt from that family's REQUIRED/FORBIDDEN legs (gate parity):
	 * count↔print/license tally cells, stock↔"actions" share-sense legs,
	 * issue↔github tracker cells.
	 */
	private const FAMILY_UNLESS_KEY = [
		'/\bcount/iu' => '/print|licen[cs]e|seatLimitCalloutBody/i',
		'/\bissue/iu' => '/github/i',
		'/\bscan[_ ]?codes?\b/iu' => '/^Columns:/i',
		'/\breorder/iu' => '/^Columns:/i',
	];

	/** Per-locale unless legs — pl/es/fr/it/pt share-sense words stay legal
	 *  on keys whose English text itself says "actions". */
	private const FORBIDDEN_UNLESS_KEY = [
		'/\bstock\b/iu' => [
			'pl' => '/actions\b/i', 'es' => '/actions\b/i', 'fr' => '/actions\b/i',
			'it' => '/actions\b/i', 'pt' => '/actions\b/i', 'pt_BR' => '/actions\b/i',
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
			// CSV header lines stay literal English in every locale — the
			// glossary exempts them via unless_key, the contract skips here.
			if (str_starts_with((string)$key, 'Columns: ')) {
				continue;
			}
			foreach (self::FORBIDDEN as $keyPat => $perLoc) {
				if (!preg_match($keyPat, (string)$key)) {
					continue;
				}
				if (isset(self::FAMILY_UNLESS_KEY[$keyPat])
					&& preg_match(self::FAMILY_UNLESS_KEY[$keyPat], (string)$key)) {
					continue;
				}
				foreach ($perLoc as $loc => $patterns) {
					// '*' legs apply to every target locale.
					$locs = ($loc === '*') ? self::LOCALES : [$loc];
					foreach ($locs as $effLoc) {
						if (isset(self::FORBIDDEN_UNLESS_KEY[$keyPat][$effLoc])
							&& preg_match(self::FORBIDDEN_UNLESS_KEY[$keyPat][$effLoc], (string)$key)) {
							continue;
						}
						$val = $cats[$effLoc][$key] ?? null;
						if (!is_string($val)) {
							continue;
						}
						// fr "grand livre" / es "libro mayor" are correct ledger senses.
						if ($effLoc === 'fr' && str_contains($val, 'grand livre')) {
							continue;
						}
						if ($effLoc === 'es' && (str_contains($val, 'libro mayor') || str_contains($val, 'libro de movimientos'))) {
							continue;
						}
						foreach ($patterns as $pat) {
							if (preg_match($pat, $val)) {
								$violations[] = sprintf('%s | %s | %s => %s', $keyPat, $effLoc, $key, $val);
							}
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
				if (isset(self::FAMILY_UNLESS_KEY[$keyPat])
					&& preg_match(self::FAMILY_UNLESS_KEY[$keyPat], (string)$key)) {
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
