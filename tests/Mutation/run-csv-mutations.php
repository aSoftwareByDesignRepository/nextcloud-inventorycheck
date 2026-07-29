#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/harness.php';

$file = 'lib/Util/Csv.php';
runMutations(dirname(__DIR__, 2), 'CsvAndLabelSheetTest', [
	[
		'name' => 'formula-sanitize-disabled',
		'file' => $file,
		'search' => "if (\$first === '=' || \$first === '+' || \$first === '-' || \$first === '@' || \$first === \"\\t\" || \$first === \"\\r\") {\n\t\t\treturn \"'\" . \$value;\n\t\t}",
		'replace' => "if (false) {\n\t\t\treturn \"'\" . \$value;\n\t\t}",
	],
	[
		'name' => 'delimiter-always-comma',
		'file' => $file,
		'search' => 'return $semi >= $comma ? \';\' : \',\';',
		'replace' => 'return \',\';',
	],
	[
		'name' => 'bom-stripped-wrong',
		'file' => $file,
		'search' => "\$raw = preg_replace('/^\\xEF\\xBB\\xBF/', '', \$raw) ?? \$raw;",
		'replace' => '$raw = $raw;',
	],
	[
		'name' => 'header-not-canonicalized',
		'file' => $file,
		'search' => '$headers = array_map(static fn (string $h): string => self::canonicalizeHeader($h), $headers);',
		'replace' => '$headers = array_map(static fn (string $h): string => trim($h), $headers);',
	],
	[
		'name' => 'de-sku-alias-dropped',
		'file' => $file,
		'search' => "'artikelnummer', 'artikel_nr', 'artikelnummer_sku' => 'sku',",
		'replace' => "'artikel_nr', 'artikelnummer_sku' => 'sku',",
	],
	[
		'name' => 'label-sheet-capacity-11',
		'file' => 'lib/Util/LabelSheet.php',
		'search' => "public const COLS = 3;\n\tpublic const ROWS = 4;",
		'replace' => "public const COLS = 3;\n\tpublic const ROWS = 3;",
	],
]);
