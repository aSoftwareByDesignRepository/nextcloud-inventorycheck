#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/harness.php';

// IV-COMPLIANCE-PACK — flip-prone lines in JournalService: hash equality,
// seq contiguity/increment, prev-linkage, genesis NULL handling, and the
// tail-truncation probe. A surviving mutant means verify can lie.
runMutations(dirname(__DIR__, 2), 'JournalServiceTest', [
	[
		'name' => 'hash-compare-becomes-inequality',
		'file' => 'lib/Service/JournalService.php',
		'search' => 'if (!hash_equals($expect, (string)$row->getEntryHash())) {',
		'replace' => 'if (hash_equals($expect, (string)$row->getEntryHash())) {',
	],
	[
		'name' => 'prev-compare-becomes-inequality',
		'file' => 'lib/Service/JournalService.php',
		'search' => 'if (!hash_equals((string)($prev ?? \'\'), (string)($row->getPrevHash() ?? \'\'))) {',
		'replace' => 'if (hash_equals((string)($prev ?? \'\'), (string)($row->getPrevHash() ?? \'\'))) {',
	],
	[
		'name' => 'seq-gap-check-inverted',
		'file' => 'lib/Service/JournalService.php',
		'search' => 'if ($seq !== $expectSeq) {',
		'replace' => 'if ($seq === $expectSeq) {',
	],
	[
		'name' => 'seq-increment-removed',
		'file' => 'lib/Service/JournalService.php',
		'search' => '$seq = $head === null ? 1 : (int)$head->getSeq() + 1;',
		'replace' => '$seq = $head === null ? 1 : (int)$head->getSeq();',
	],
	[
		'name' => 'genesis-prev-not-null',
		'file' => 'lib/Service/JournalService.php',
		'search' => '$prev = $head === null ? null : (string)$head->getEntryHash();',
		'replace' => '$prev = (string)$head->getEntryHash();',
	],
	[
		'name' => 'tail-probe-strict-gt-becomes-ge',
		'file' => 'lib/Service/JournalService.php',
		'search' => 'if ($maxMovementId > (int)$head->getMovementId()) {',
		'replace' => 'if ($maxMovementId >= (int)$head->getMovementId()) {',
	],
	[
		'name' => 'sha1-instead-of-sha256',
		'file' => 'lib/Service/JournalService.php',
		'search' => 'return hash(\'sha256\', $raw . $canonical);',
		'replace' => 'return hash(\'sha256\', $canonical . $raw);',
	],
	[
		'name' => 'intact-when-chain-empty-only',
		'file' => 'lib/Service/JournalService.php',
		'search' => 'return [\'intact\' => true, \'checked\' => $checked,',
		'replace' => 'return [\'intact\' => $checked > 0, \'checked\' => $checked,',
	],
]);
