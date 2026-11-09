#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/harness.php';

$file = 'lib/Service/CsvImportService.php';
runMutations(dirname(__DIR__, 2), 'testCsv', [
	[
		'name' => 'upsert-match-disabled',
		'file' => $file,
		'search' => 'return $ids === [] ? null : (int)array_key_first($ids);',
		'replace' => 'return null;',
	],
	[
		'name' => 'upsert-ambiguous-picks-first',
		'file' => $file,
		'search' => "if (count(\$ids) > 1) {\n\t\t\treturn self::MATCH_AMBIGUOUS;\n\t\t}",
		'replace' => "if (count(\$ids) > 1) {\n\t\t\treturn (int)array_key_first(\$ids);\n\t\t}",
	],
	[
		'name' => 'upsert-repeat-target-allowed',
		'file' => $file,
		'search' => "if (\$updateItemId !== null && isset(\$seenIds[\$updateItemId])) {",
		'replace' => "if (false) {",
	],
	[
		'name' => 'upsert-merge-all-columns',
		'file' => $file,
		'search' => "foreach (\$present as \$col => \$value) {\n\t\t\t\t\tif (array_key_exists(\$col, \$raw)) {",
		'replace' => "foreach (\$present as \$col => \$value) {\n\t\t\t\t\tif (true) {",
	],
	[
		'name' => 'upsert-opening-reposted',
		'file' => $file,
		'search' => "if (\$updateItemId === null && (\$openingLocCode !== '' || \$openingQtyRaw !== '')) {",
		'replace' => "if (\$openingLocCode !== '' || \$openingQtyRaw !== '') {",
	],
	[
		'name' => 'upsert-deactivate-guard-dropped',
		'file' => $file,
		'search' => "if (array_key_exists('active', \$raw) && !\$active && \$matched->getActive()) {",
		'replace' => "if (false) {",
	],
	[
		'name' => 'upsert-target-baseline-zero',
		'file' => $file,
		'search' => '&& $targetStock !== null && $targetStock < $matched->getReorderLevel()) {',
		'replace' => '&& $targetStock !== null && $targetStock < 0) {',
	],
	[
		'name' => 'upsert-name-required-on-update',
		'file' => $file,
		'search' => "if ((\$updateItemId === null || array_key_exists('name', \$raw))",
		'replace' => "if (true",
	],
	[
		'name' => 'upsert-update-treated-as-create',
		'file' => $file,
		'search' => "if (\$row['update_item_id'] !== null) {",
		'replace' => "if (\$row['update_item_id'] === null) {",
	],
]);
