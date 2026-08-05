<?php

declare(strict_types=1);

/**
 * Mutation harness: stocktake create page + IME dialog pad contracts.
 */

$root = dirname(__DIR__, 2);
require $root . '/tests/Mutation/harness.php';

$phpunit = $root . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	fwrite(STDERR, "phpunit missing — run composer install in inventorycheck\n");
	exit(1);
}

runMutations($root, 'StocktakeCreatePageContractTest|BachusSimplicityUxContractTest::testPass7OneClickStocktakeAndQuieterChrome|BachusSimplicityUxContractTest::testStocktakeDutyCheckStyleHowTo|BachusSimplicityUxContractTest::testConfirmOnlyBookingAndResponsiveTables|AzcShellParityContractTest::testSkipLinkIsAbsolutelyPositionedOffscreen', [
	[
		'name' => 'drop-stocktake-create-route',
		'file' => 'appinfo/routes.php',
		'search' => "['name' => 'page#stocktakeNew', 'url' => '/stocktake/create', 'verb' => 'GET'],",
		'replace' => "// mutated: stocktake create route removed",
	],
	[
		'name' => 'create-route-collides-with-new',
		'file' => 'appinfo/routes.php',
		'search' => "'/stocktake/create'",
		'replace' => "'/stocktake/new'",
	],
	[
		'name' => 'drop-office-gate',
		'file' => 'js/app.js',
		'search' => 'Office access required',
		'replace' => 'Office access optional',
	],
	[
		'name' => 'drop-location-id-floor-guard',
		'file' => 'js/app.js',
		'search' => 'if (!locId || locId < 1 || locId !== Math.floor(locId))',
		'replace' => 'if (false)',
	],
	[
		'name' => 'drop-double-tap-busy-arm',
		'file' => 'js/app.js',
		'search' => "setBusy(true);\n\t\t\t\t\t\topts.onPick(loc);",
		'replace' => "opts.onPick(loc);",
	],
	[
		'name' => 'drop-double-tap-busy-comment',
		'file' => 'js/app.js',
		'search' => 'Arm busy before the async create path to kill double-tap races.',
		'replace' => 'Busy armed after pick (race window).',
	],
	[
		'name' => 'drop-label-sr-only',
		'file' => 'js/app.js',
		'search' => 'labelSrOnly: true',
		'replace' => 'labelSrOnly: false',
	],
	[
		'name' => 'drop-stocktake-howto-card',
		'file' => 'js/app.js',
		'search' => 'function stocktakeHowToCard(ctx, opts) {',
		'replace' => 'function stocktakeHowToDropped(ctx, opts) {',
	],
	[
		'name' => 'drop-page-howto-card',
		'file' => 'js/app.js',
		'search' => 'function pageHowToCard(ctx, page) {',
		'replace' => 'function pageHowToDropped(ctx, page) {',
	],
	[
		'name' => 'drop-howto-card-core',
		'file' => 'js/app.js',
		'search' => 'function howToCard(ctx, opts) {',
		'replace' => 'function howToDropped(ctx, opts) {',
	],
	[
		'name' => 'drop-with-settings-howto',
		'file' => 'js/app.js',
		'search' => 'function withSettingsHowTo(ctx, section, kids) {',
		'replace' => 'function withSettingsHowtoDropped(ctx, section, kids) {',
	],
	[
		'name' => 'howto-readd-empty-class',
		'file' => 'js/app.js',
		'search' => "className: 'iv-card iv-howto'",
		'replace' => "className: 'iv-card iv-empty iv-empty--quickstart iv-howto'",
	],
	[
		'name' => 'howto-drop-flex-column',
		'file' => 'css/app.css',
		'search' => ".iv-howto,\n.iv-stocktake-howto {\n\tdisplay: flex;\n\tflex-direction: column;",
		'replace' => ".iv-howto,\n.iv-stocktake-howto {\n\tdisplay: grid;\n\tgrid-template-columns: auto minmax(0, 1fr);",
	],
	[
		'name' => 'drop-howto-grid-520',
		'file' => 'css/app.css',
		'search' => '@media (min-width: 520px) {',
		'replace' => '@media (min-width: 9999px) {',
	],
	[
		'name' => 'drop-howto-container-narrow',
		'file' => 'css/app.css',
		'search' => '@container iv-howto (max-width: 519px) {',
		'replace' => '@container iv-howto (max-width: 1px) {',
	],
	[
		'name' => 'drop-howto-container-type',
		'file' => 'css/app.css',
		'search' => "\tcontainer-type: inline-size;\n\tcontainer-name: iv-howto;",
		'replace' => "\t/* container-type removed */\n\t/* container-name removed */",
	],
	[
		'name' => 'more-menu-inline-body',
		'file' => 'css/app.css',
		'search' => "#content.app-inventorycheck #app-content.iv-app .iv-actions-more__body,\n.iv-actions-more__body {\n\tposition: absolute !important;\n\ttop: calc(100% + 0.35rem);",
		'replace' => "#content.app-inventorycheck #app-content.iv-app .iv-actions-more__body,\n.iv-actions-more__body {\n\tposition: static !important;\n\ttop: auto;",
	],
	[
		'name' => 'page-header-three-col-again',
		'file' => 'css/common/shell-chrome.css',
		'search' => "\tgrid-template-columns: 56px minmax(0, 1fr);",
		'replace' => "\tgrid-template-columns: 56px minmax(0, 1fr) auto;",
	],
	[
		'name' => 'drop-actions-more-menu-helper',
		'file' => 'js/app.js',
		'search' => 'function actionsMoreMenu(summaryText, kids) {',
		'replace' => 'function actionsMoreMenuRemoved(summaryText, kids) {',
	],
	[
		'name' => 'ime-drop-dialog-body-host',
		'file' => 'js/common/keep-focused-visible.js',
		'search' => "'.iv-dialog__body',",
		'replace' => "'._iv-dialog-body-missing',",
	],
	[
		'name' => 'ime-drop-soft-keyboard-gate',
		'file' => 'js/common/keep-focused-visible.js',
		'search' => 'return win.visualViewport.height < win.innerHeight - KEYBOARD_SHRINK_PX;',
		'replace' => 'return true;',
	],
	[
		'name' => 'css-dialog-body-no-scroll',
		'file' => 'css/app.css',
		'search' => ".iv-dialog__body {\n\tdisplay: flex;\n\tflex-direction: column;\n\tgap: var(--iv-space-3, 12px);\n\tflex: 1 1 auto;\n\tmin-height: 0;\n\toverflow-x: hidden;\n\toverflow-y: auto;",
		'replace' => ".iv-dialog__body {\n\tdisplay: flex;\n\tflex-direction: column;\n\tgap: var(--iv-space-3, 12px);\n\tflex: 1 1 auto;\n\tmin-height: 0;\n\toverflow-x: hidden;\n\toverflow-y: visible;",
	],
]);
