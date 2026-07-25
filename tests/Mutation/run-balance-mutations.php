#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: BalanceMapper negative/nonZero filter + ordering.
 */

require __DIR__ . '/harness.php';

$file = 'lib/Db/BalanceMapper.php';

runMutations(dirname(__DIR__, 2), 'UnknownAndNegativeBalancesIntegrationTest', [
	[
		'name' => 'negative-filter-becomes-gte-zero',
		'file' => $file,
		'search' => "\$conds[] = \$qb->expr()->lt('qty', \$qb->createNamedParameter(0, \\PDO::PARAM_INT));",
		'replace' => "\$conds[] = \$qb->expr()->gte('qty', \$qb->createNamedParameter(0, \\PDO::PARAM_INT));",
	],
	[
		'name' => 'negative-branch-ignored',
		'file' => $file,
		'search' => "if (\$negativeOnly) {\n\t\t\t\t\$conds[] = \$qb->expr()->lt('qty', \$qb->createNamedParameter(0, \\PDO::PARAM_INT));\n\t\t\t} elseif (\$nonZero) {",
		'replace' => "if (false) {\n\t\t\t\t\$conds[] = \$qb->expr()->lt('qty', \$qb->createNamedParameter(0, \\PDO::PARAM_INT));\n\t\t\t} elseif (\$nonZero) {",
	],
	[
		'name' => 'nonzero-filter-dropped',
		'file' => $file,
		'search' => "\$conds[] = \$qb->expr()->neq('qty', \$qb->createNamedParameter(0, \\PDO::PARAM_INT));",
		'replace' => "\$conds[] = \$qb->expr()->eq('qty', \$qb->createNamedParameter(0, \\PDO::PARAM_INT));",
	],
]);
