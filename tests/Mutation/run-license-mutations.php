#!/usr/bin/env php
<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
$file = 'lib/License/Iv2Codec.php';
runMutations(dirname(__DIR__, 2), 'Iv2CodecTest', [
	['name' => 'product-check-dropped', 'file' => $file, 'search' => "if ((\$payload['product'] ?? null) !== self::PRODUCT) {\n\t\t\treturn false;\n\t\t}", 'replace' => "if (false) {\n\t\t\treturn false;\n\t\t}"],
	['name' => 'version-check-dropped', 'file' => $file, 'search' => "if ((\$payload['v'] ?? null) !== self::VERSION) {\n\t\t\treturn false;\n\t\t}", 'replace' => "if (false) {\n\t\t\treturn false;\n\t\t}"],
	['name' => 'seats-devices-sum-ignored', 'file' => $file, 'search' => "if (\$seats + \$devices <= 0) {\n\t\t\treturn false;\n\t\t}", 'replace' => "if (false) {\n\t\t\treturn false;\n\t\t}"],
	['name' => 'date-order-ignored', 'file' => $file, 'search' => "if (\$payload['validUntil'] < \$payload['issuedAt']) {\n\t\t\treturn false;\n\t\t}", 'replace' => "if (false) {\n\t\t\treturn false;\n\t\t}"],
	['name' => 'bundle-false-accepted', 'file' => $file, 'search' => "if (\$payload['bundle'] !== true) {\n\t\t\t\t// bundle must be absent when false (canonicalisation rule).\n\t\t\t\treturn false;\n\t\t\t}", 'replace' => "if (false) {\n\t\t\t\treturn false;\n\t\t\t}"],
	['name' => 'canonical-equality-skipped', 'file' => $file, 'search' => 'if (!hash_equals(self::canonicalJson($payload), $payloadBytes)) {', 'replace' => 'if (false && !hash_equals(self::canonicalJson($payload), $payloadBytes)) {'],
	['name' => 'validity-inclusive-flipped', 'file' => $file, 'search' => 'return $today <= $validUntil;', 'replace' => 'return $today < $validUntil;'],
]);
