#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/harness.php';

runMutations(dirname(__DIR__, 2), 'BoolParamTest', [
	[
		'name' => 'string-zero-treated-true',
		'file' => 'lib/Service/BoolParam.php',
		'search' => "if (\$value === 0 || \$value === '0') {\n\t\t\treturn false;\n\t\t}",
		'replace' => "if (\$value === 0) {\n\t\t\treturn false;\n\t\t}",
	],
]);
