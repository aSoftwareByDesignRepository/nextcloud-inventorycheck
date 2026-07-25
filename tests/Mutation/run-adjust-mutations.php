#!/usr/bin/env php
<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
$file = 'lib/Service/AdjustSemantics.php';
runMutations(dirname(__DIR__, 2), 'AdjustSemanticsTest', [
	['name' => 'noop-check-removed', 'file' => $file, 'search' => "if (\$delta === 0) {\n\t\t\tthrow new ValidationException('invalid_qty', 'noop');\n\t\t}", 'replace' => "if (false) {\n\t\t\tthrow new ValidationException('invalid_qty', 'noop');\n\t\t}"],
	['name' => 'set-neg-policy-ignored', 'file' => $file, 'search' => 'if (!$allowNegative && $qty < 0) {', 'replace' => 'if (false && $qty < 0) {'],
	['name' => 'delta-would-neg-ignored', 'file' => $file, 'search' => 'if (!$allowNegative && $qtyAfter < 0 && $qtyAfter < $currentQty) {', 'replace' => 'if (false && $qtyAfter < 0 && $qtyAfter < $currentQty) {'],
]);
