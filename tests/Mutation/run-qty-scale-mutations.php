#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/harness.php';

runMutations(dirname(__DIR__, 2), 'QtyScaleTest', [
	[
		'name' => 'factor-always-1',
		'file' => 'lib/Service/QtyScale.php',
		'search' => 'return self::isFractional($config) ? self::FACTOR : 1;',
		'replace' => 'return 1;',
	],
	[
		'name' => 'serial-unit-hardcoded-1',
		'file' => 'lib/Service/QtyScale.php',
		'search' => "public static function serialUnit(IConfig \$config): int\n\t{\n\t\treturn self::factor(\$config);\n\t}",
		'replace' => "public static function serialUnit(IConfig \$config): int\n\t{\n\t\treturn 1;\n\t}",
	],
	[
		'name' => 'to-display-ignores-scale',
		'file' => 'lib/Service/QtyScale.php',
		'search' => "if (self::current(\$config) === self::SCALE_INT) {\n\t\t\treturn \$storage;\n\t\t}",
		'replace' => "if (false) {\n\t\t\treturn \$storage;\n\t\t}",
	],
	[
		'name' => 'format-balance-skips-qty',
		'file' => 'lib/Service/QtyScale.php',
		'search' => "if (array_key_exists('qty', \$balance) && is_int(\$balance['qty'])) {\n\t\t\t\$balance['qty'] = self::toDisplay(\$config, \$balance['qty']);\n\t\t}",
		'replace' => "if (false) {\n\t\t\t\$balance['qty'] = self::toDisplay(\$config, \$balance['qty']);\n\t\t}",
	],
	[
		'name' => 'int-scale-accepts-fraction',
		'file' => 'lib/Service/QtyScale.php',
		'search' => "if (\$raw === '' || preg_match('/^-?\\d+\$/', \$raw) !== 1) {\n\t\t\t\tthrow new ValidationException('invalid_qty', '', [['field' => 'qty', 'code' => 'invalid_qty']]);\n\t\t\t}",
		'replace' => "if (\$raw === '') {\n\t\t\t\tthrow new ValidationException('invalid_qty', '', [['field' => 'qty', 'code' => 'invalid_qty']]);\n\t\t\t}",
	],
]);
