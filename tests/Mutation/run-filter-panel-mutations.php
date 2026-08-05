<?php

declare(strict_types=1);

/**
 * Mutation harness for InventoryCheck filter-panel layout contracts.
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

$suiteFilters = [
	'FilterPanelLayoutContractTest',
	'AzcShellParityContractTest::testFilterPanelAndDialogTokensPresent',
];

$baseline = run_unit($root, $phpunit, $suiteFilters);
if ($baseline !== 0) {
	fwrite(STDERR, "Baseline filter panel tests failed\n");
	exit($baseline);
}

/** @var list<array{name:string,file:string,from:string,to:string}> $mutations */
$mutations = [
	[
		'name' => 'drop-filter-panel-class',
		'file' => $root . '/js/app.js',
		'from' => "className: 'iv-card iv-filter-panel'",
		'to' => "className: 'iv-card iv-toolbar'",
	],
	[
		'name' => 'bare-filterbar-css-returns',
		'file' => $root . '/css/app.css',
		'from' => '.iv-filterbar:not(.iv-filter-panel__form)',
		'to' => '.iv-filterbar',
	],
	[
		'name' => 'drop-form-select-on-movements',
		'file' => $root . '/js/app.js',
		'from' => "className: 'iv-input form-select'",
		'to' => "className: 'iv-input'",
	],
	[
		'name' => 'transfer-group-falls-back-to-filter-bar',
		'file' => $root . '/js/app.js',
		'from' => 'iv-callout iv-callout--info iv-filter-active',
		'to' => 'iv-filter-bar',
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
	$code = run_unit($root, $phpunit, $suiteFilters);
	file_put_contents($m['file'], $original);
	if ($code !== 0) {
		$killed++;
		fwrite(STDOUT, "KILLED {$m['name']}\n");
	} else {
		$survived[] = $m['name'];
		fwrite(STDERR, "SURVIVED {$m['name']}\n");
	}
}

fwrite(STDOUT, sprintf("Killed %d / %d\n", $killed, count($mutations)));
if ($survived !== []) {
	fwrite(STDERR, 'Survivors: ' . implode(', ', $survived) . "\n");
	exit(1);
}
exit(0);
