#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/harness.php';

// IV-CUSTOM-FIELDS — flip-prone lines in CustomFieldService: empty→null
// normalization, inactive-def filtering, required-on-write merge, stale
// select flagging, type/searchable/column-map boundaries.
runMutations(dirname(__DIR__, 2), 'CustomFieldServiceTest', [
	[
		'name' => 'empty-string-no-longer-clears',
		'file' => 'lib/Service/CustomFieldService.php',
		'search' => 'if ($raw === null || $raw === \'\') {',
		'replace' => 'if ($raw === null) {',
	],
	[
		'name' => 'inactive-defs-validated',
		'file' => 'lib/Service/CustomFieldService.php',
		'search' => 'if ($field === null || !$field->getActive()) {',
		'replace' => 'if ($field === null || $field->getActive()) {',
	],
	[
		'name' => 'required-check-inverted',
		'file' => 'lib/Service/CustomFieldService.php',
		'search' => 'if (!$field->getRequired()) {',
		'replace' => 'if ($field->getRequired()) {',
	],
	[
		'name' => 'required-ignores-normalized-clear',
		'file' => 'lib/Service/CustomFieldService.php',
		'search' => '$val = array_key_exists($id, $normalized) ? $normalized[$id] : ($existingValues[$id] ?? null);',
		'replace' => '$val = $existingValues[$id] ?? null;',
	],
	[
		'name' => 'stale-flag-inverted',
		'file' => 'lib/Service/CustomFieldService.php',
		'search' => '&& !in_array($value, $field->options(), true);',
		'replace' => '&& in_array($value, $field->options(), true);',
	],
	[
		'name' => 'select-dedupe-keeps-duplicates',
		'file' => 'lib/Service/CustomFieldService.php',
		'search' => 'if ($opt !== \'\' && !in_array($opt, $options, true)) {',
		'replace' => 'if ($opt !== \'\' && in_array($opt, $options, true)) {',
	],
	[
		'name' => 'text-limit-becomes-lt',
		'file' => 'lib/Service/CustomFieldService.php',
		'search' => 'mb_strlen($v) > ItemField::MAX_VALUE_CHARS',
		'replace' => 'mb_strlen($v) < ItemField::MAX_VALUE_CHARS',
	],
	[
		'name' => 'select-accepts-stale',
		'file' => 'lib/Service/CustomFieldService.php',
		'search' => 'if (!in_array($v, $field->options(), true)) {',
		'replace' => 'if (in_array($v, $field->options(), true)) {',
	],
	[
		'name' => 'searchable-drops-select',
		'file' => 'lib/Service/CustomFieldService.php',
		'search' => 'in_array($f->getType(), [\'text\', \'select\'], true)',
		'replace' => 'in_array($f->getType(), [\'text\'], true)',
	],
	[
		'name' => 'column-map-ignores-dashes',
		'file' => 'lib/Service/CustomFieldService.php',
		'search' => 'str_replace([\' \', \'-\'], \'_\', trim($f->getName()))',
		'replace' => 'str_replace(\' \', \'_\', trim($f->getName()))',
	],
]);
