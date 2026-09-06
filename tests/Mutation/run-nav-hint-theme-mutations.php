<?php

declare(strict_types=1);

/**
 * Mutation harness: active nav hint must use on-fill text across NC themes.
 */

$root = dirname(__DIR__, 2);
require $root . '/tests/Mutation/harness.php';

$phpunit = $root . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	fwrite(STDERR, "phpunit missing — run composer install in inventorycheck\n");
	exit(1);
}

runMutations($root, 'AzcShellParityContractTest::testNavigationCssKeepsSubmenuRail', [
	[
		'name' => 'active-hint-back-to-maxcontrast',
		'file' => 'css/navigation.css',
		'search' => "#app-navigation .nav-menu > li.active > a .iv-nav__hint,\n#app-navigation .nav-menu > li > a[aria-current=\"page\"] .iv-nav__hint,\n#app-navigation .nav-submenu > li.active > a .iv-nav__hint,\n#app-navigation .nav-submenu > li > a[aria-current=\"page\"] .iv-nav__hint,\n#app-navigation .nav-menu > li.active > a:hover .iv-nav__hint,\n#app-navigation .nav-menu > li > a[aria-current=\"page\"]:hover .iv-nav__hint,\n#app-navigation .nav-submenu > li.active > a:hover .iv-nav__hint,\n#app-navigation .nav-submenu > li > a[aria-current=\"page\"]:hover .iv-nav__hint {\n    color: var(--color-primary-element-text);\n}",
		'replace' => "#app-navigation .nav-menu > li.active > a .iv-nav__hint,\n#app-navigation .nav-menu > li > a[aria-current=\"page\"] .iv-nav__hint {\n    color: var(--color-text-maxcontrast);\n}",
	],
	[
		'name' => 'drop-active-hint-comment-guard',
		'file' => 'css/navigation.css',
		'search' => 'Maxcontrast hints stay dark-on-dark',
		'replace' => 'Hints keep muted color always',
	],
	[
		'name' => 'nav-rail-width-100-again',
		'file' => 'css/navigation.css',
		'search' => "#content.app-inventorycheck #app-navigation {\n    text-align: left;\n    align-items: stretch;\n}",
		'replace' => "#content.app-inventorycheck #app-navigation {\n    text-align: left;\n    align-items: stretch;\n    width: 100%;\n}",
	],
	[
		'name' => 'drop-nav-drawer-width-pin',
		'file' => 'css/navigation.css',
		'search' => 'never set width:100% on #app-navigation',
		'replace' => 'sidebar may use width 100 percent',
	],
]);
