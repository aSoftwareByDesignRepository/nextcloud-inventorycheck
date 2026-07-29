#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/harness.php';

$code128 = 'lib/Util/Code128Svg.php';
$label = 'lib/Util/LabelSvg.php';

runMutations(dirname(__DIR__, 2), 'Code128SvgTest|LabelSvgTest', [
	[
		'name' => 'code128-start-a-instead-of-b',
		'file' => $code128,
		'search' => "private const START_B = 104;",
		'replace' => "private const START_B = 103;",
	],
	[
		'name' => 'code128-checksum-dropped',
		'file' => $code128,
		'search' => "\$values[] = self::checksum(\$values);\n\t\t\$values[] = self::STOP;",
		'replace' => "\$values[] = self::STOP;",
	],
	[
		'name' => 'code128-empty-accepted',
		'file' => $code128,
		'search' => "if (\$payload === '') {\n\t\t\tthrow new \\InvalidArgumentException('barcode_payload_required');\n\t\t}",
		'replace' => "if (false) {\n\t\t\tthrow new \\InvalidArgumentException('barcode_payload_required');\n\t\t}",
	],
	[
		'name' => 'code128-charset-gate-dropped',
		'file' => $code128,
		'search' => "if (\$ord < 32 || \$ord > 126) {\n\t\t\t\tthrow new \\InvalidArgumentException('barcode_charset');\n\t\t\t}",
		'replace' => "if (false) {\n\t\t\t\tthrow new \\InvalidArgumentException('barcode_charset');\n\t\t\t}",
	],
	[
		'name' => 'label-barcode-omitted',
		'file' => $label,
		'search' => "\$barcode = Code128Svg::barsGroup(\$scanCode, 20.0, 196.0, 280.0, 44.0);",
		'replace' => "\$barcode = '';",
	],
	[
		'name' => 'label-code-id-dropped',
		'file' => $label,
		'search' => "'<text id=\"iv-label-code\" x=\"160\" y=\"322\" text-anchor=\"middle\" fill=\"#111111\"'",
		'replace' => "'<text id=\"iv-label-code-x\" x=\"160\" y=\"322\" text-anchor=\"middle\" fill=\"#111111\"'",
	],
]);
