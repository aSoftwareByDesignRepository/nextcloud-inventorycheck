#!/usr/bin/env php
<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
$file = 'lib/Service/MovementMath.php';
runMutations(dirname(__DIR__, 2), 'MovementMathTest', [
	['name' => 'qty-min-zero', 'file' => $file, 'search' => 'return $qty >= self::QTY_MIN && $qty <= self::QTY_MAX;', 'replace' => 'return $qty > self::QTY_MIN && $qty <= self::QTY_MAX;'],
	['name' => 'qty-max-open', 'file' => $file, 'search' => 'return $qty >= self::QTY_MIN && $qty <= self::QTY_MAX;', 'replace' => 'return $qty >= self::QTY_MIN && $qty < self::QTY_MAX;'],
	['name' => 'allow-negative-ignored', 'file' => $file, 'search' => "if (\$allowNegative) {\n\t\t\treturn false;\n\t\t}", 'replace' => "if (false) {\n\t\t\treturn false;\n\t\t}"],
	['name' => 'after-nonneg-ignored', 'file' => $file, 'search' => "if (\$qtyAfter >= 0) {\n\t\t\treturn false;\n\t\t}", 'replace' => "if (false) {\n\t\t\treturn false;\n\t\t}"],
	['name' => 'more-negative-inverted', 'file' => $file, 'search' => 'return $qtyAfter < $qtyBefore;', 'replace' => 'return $qtyAfter > $qtyBefore;'],
	['name' => 'receive-sign-flipped', 'file' => $file, 'search' => "'receive', 'transfer_in' => \$qty,", 'replace' => "'receive', 'transfer_in' => -\$qty,"],
]);
