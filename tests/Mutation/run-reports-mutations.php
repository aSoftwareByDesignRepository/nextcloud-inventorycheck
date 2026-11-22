#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/harness.php';

// IV-REPORTS-SURFACE — flip-prone lines: the untouched-window comparison,
// the zero-qty exclusion, the any-movement clock max(), and the
// unpriced-on-hand counting in locationValue.
runMutations(dirname(__DIR__, 2), 'ReportServiceTest', [
	[
		'name' => 'dead-stock-window-becomes-inclusive',
		'file' => 'lib/Service/ReportService.php',
		'search' => 'if ($lastActivity >= $threshold) {',
		'replace' => 'if ($lastActivity > $threshold) {',
	],
	[
		'name' => 'dead-stock-keeps-zero-qty',
		'file' => 'lib/Service/ReportService.php',
		'search' => 'if ($qty <= 0) {
				continue;
			}
			$lastActivity = max(',
		'replace' => 'if ($qty < 0) {
				continue;
			}
			$lastActivity = max(',
	],
	[
		'name' => 'dead-stock-clock-uses-min',
		'file' => 'lib/Service/ReportService.php',
		'search' => '$lastActivity = max($item->getCreatedAt(), $lastMove[$itemId] ?? 0);',
		'replace' => '$lastActivity = min($item->getCreatedAt(), $lastMove[$itemId] ?? 0);',
	],
	[
		'name' => 'location-value-counts-all-unpriced',
		'file' => 'lib/Service/ReportService.php',
		'search' => "'unpricedCount' => count(\$unpricedItems),",
		'replace' => "'unpricedCount' => count(\$prices) - count(\$unpricedItems),",
	],
	[
		'name' => 'location-value-keeps-zero-qty-locations',
		'file' => 'lib/Service/ReportService.php',
		'search' => 'if ($qty <= 0) {
					continue;
				}
				$itemsAtLoc',
		'replace' => 'if ($qty < 0) {
					continue;
				}
				$itemsAtLoc',
	],
]);
