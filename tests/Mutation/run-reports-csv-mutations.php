#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/harness.php';

// IV-REPORTS-SURFACE CSV kinds — the days bound in the dead_stock export.
runMutations(dirname(__DIR__, 2), 'CsvExportServiceReportsTest', [
	[
		'name' => 'days-validation-drops-lower-bound',
		'file' => 'lib/Service/CsvExportService.php',
		'search' => 'if ($days < 1 || $days > 3650) {',
		'replace' => 'if ($days > 3650) {',
	],
	[
		'name' => 'days-validation-drops-upper-bound',
		'file' => 'lib/Service/CsvExportService.php',
		'search' => 'if ($days < 1 || $days > 3650) {',
		'replace' => 'if ($days < 1) {',
	],
	[
		'name' => 'dead-stock-default-not-90',
		'file' => 'lib/Service/CsvExportService.php',
		'search' => "'dead_stock' => \$this->exportDeadStock(\$actorUid, \$days ?? 90, \$lang),",
		'replace' => "'dead_stock' => \$this->exportDeadStock(\$actorUid, \$days ?? 30, \$lang),",
	],
]);
