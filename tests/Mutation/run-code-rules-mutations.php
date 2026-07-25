#!/usr/bin/env php
<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
$file = 'lib/Service/CodeRules.php';
runMutations(dirname(__DIR__, 2), 'CodeRulesTest', [
	['name' => 'self-skip-removed', 'file' => $file, 'search' => "if (\$selfId !== null && (int)\$row['id'] === \$selfId) {\n\t\t\t\tcontinue;\n\t\t\t}", 'replace' => "if (false) {\n\t\t\t\tcontinue;\n\t\t\t}"],
	['name' => 'cross-sku-check-dropped', 'file' => $file, 'search' => "if (\$sku === \$otherSku || \$sku === \$otherScan) {\n\t\t\t\treturn true;\n\t\t\t}", 'replace' => "if (false) {\n\t\t\t\treturn true;\n\t\t\t}"],
	['name' => 'kind-list-tampered', 'file' => $file, 'search' => "return ['warehouse', 'shelf', 'van', 'site', 'other'];", 'replace' => "return ['warehouse', 'shelf', 'van', 'site'];"],
]);
