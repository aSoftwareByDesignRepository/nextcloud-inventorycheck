<?php

declare(strict_types=1);

/**
 * Mutation harness for InventoryCheck settings multipage contracts.
 * Proves SettingsPagesContractTest + SettingsSectionCatalogTest + JS redirect
 * tests kill real drift (catalog, routes, anchors, section switches).
 */

$root = dirname(__DIR__, 2);
$phpunit = $root . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	fwrite(STDERR, "phpunit missing — run composer install in inventorycheck\n");
	exit(1);
}

/**
 * @param list<string> $filters
 */
function run_unit(string $root, string $phpunit, array $filters): int
{
	$filter = implode('|', $filters);
	$cmd = escapeshellarg(PHP_BINARY) . ' -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' --configuration ' . escapeshellarg($root . '/phpunit.xml')
		. ' --testsuite unit'
		. ' --filter ' . escapeshellarg($filter);
	passthru($cmd, $code);
	return (int)$code;
}

function run_node(string $root): int
{
	passthru('cd ' . escapeshellarg($root) . ' && node --test tests/js/settings-pages.test.mjs', $code);
	return (int)$code;
}

$suiteFilters = [
	'SettingsSectionCatalogTest',
	'SettingsPagesContractTest',
];

$baseline = run_unit($root, $phpunit, $suiteFilters);
if ($baseline !== 0) {
	fwrite(STDERR, "Baseline settings pages tests failed\n");
	exit($baseline);
}
// Optional host-side JS suite when node is available (Docker MSI is PHP-only).
if (is_executable('/usr/bin/node') || trim((string)shell_exec('command -v node')) !== '') {
	$nodeBase = run_node($root);
	if ($nodeBase !== 0) {
		fwrite(STDERR, "Baseline settings-pages JS tests failed\n");
		exit($nodeBase);
	}
}

/** @var list<array{name:string,file:string,from:string,to:string,kill:string}> $mutations */
$mutations = [
	[
		'name' => 'default-section-to-license',
		'file' => $root . '/lib/Service/SettingsSectionCatalog.php',
		'from' => "public const DEFAULT_SECTION = 'access';",
		'to' => "public const DEFAULT_SECTION = 'license';",
		'kill' => 'php',
	],
	[
		'name' => 'drop-access-from-sections',
		'file' => $root . '/lib/Service/SettingsSectionCatalog.php',
		'from' => "\t\t'access',\n",
		'to' => '',
		'kill' => 'php',
	],
	[
		'name' => 'license-anchor-points-at-support',
		'file' => $root . '/lib/Service/SettingsSectionCatalog.php',
		'from' => "'iv-license' => 'license',",
		'to' => "'iv-license' => 'support',",
		'kill' => 'php',
	],
	[
		'name' => 'js-anchor-license-to-access',
		'file' => $root . '/js/settings-legacy-redirect.js',
		'from' => "'iv-license': 'license',",
		'to' => "'iv-license': 'access',",
		'kill' => 'php',
	],
	[
		'name' => 'routes-drop-license-slug',
		'file' => $root . '/appinfo/routes.php',
		'from' => 'access|office|notifications|quantities|location-access|connections|policies|license|support',
		'to' => 'access|office|notifications|quantities|location-access|connections|policies|support',
		'kill' => 'php',
	],
	[
		'name' => 'app-js-drop-license-section-gate',
		'file' => $root . '/js/app.js',
		'from' => "if (section === 'license') {",
		'to' => "if (section === 'license-disabled') {",
		'kill' => 'php',
	],
];

$killed = 0;
$survived = [];

foreach ($mutations as $m) {
	$original = (string)file_get_contents($m['file']);
	if (!str_contains($original, $m['from'])) {
		fwrite(STDERR, "Mutation source missing for {$m['name']}\n");
		$survived[] = $m['name'] . ' (source missing)';
		continue;
	}
	file_put_contents($m['file'], str_replace($m['from'], $m['to'], $original));
	$dead = false;
	try {
		$hasNode = is_executable('/usr/bin/node') || trim((string)shell_exec('command -v node')) !== '';
		if ($hasNode && ($m['kill'] === 'node' || $m['kill'] === 'both')) {
			$dead = run_node($root) !== 0;
		}
		if (!$dead && ($m['kill'] === 'php' || $m['kill'] === 'both' || $m['kill'] === 'node')) {
			// PHP contract also pins the JS legacy map — works inside Docker MSI.
			$dead = run_unit($root, $phpunit, $suiteFilters) !== 0;
		}
	} finally {
		file_put_contents($m['file'], $original);
	}
	if ($dead) {
		$killed++;
		echo "Killed {$m['name']}\n";
	} else {
		$survived[] = $m['name'];
		fwrite(STDERR, "SURVIVED {$m['name']}\n");
	}
}

$total = count($mutations);
echo "Killed {$killed} / {$total}\n";
if ($survived !== []) {
	fwrite(STDERR, 'Survivors: ' . implode(', ', $survived) . "\n");
	exit(1);
}
exit(0);
