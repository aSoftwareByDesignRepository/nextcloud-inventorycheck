#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/harness.php';

// F2 Wave A — flip-prone lines in the custody surface: checkout delta sign,
// person-loanability gate, CAS return cap, anonymize open-loan guard,
// notify dedupe. A surviving mutant means loans can corrupt stock/audit.
runMutations(dirname(__DIR__, 2), 'CustodyLending|LoanService', [
	[
		'name' => 'checkout-sign-flipped',
		'file' => 'lib/Service/MovementMath.php',
		'search' => "'issue', 'transfer_out', 'checkout' => -\$qty,",
		'replace' => "'issue', 'transfer_out', 'checkout' => \$qty,",
	],
	[
		'name' => 'loanability-or-becomes-and',
		'file' => 'lib/Service/LoanService.php',
		'search' => 'if (!$person->getActive() || $person->getAnonymized()) {',
		'replace' => 'if (!$person->getActive() && $person->getAnonymized()) {',
	],
	[
		'name' => 'condition-whitelist-inverted',
		'file' => 'lib/Service/LoanService.php',
		'search' => 'if (!in_array($cond, self::CONDITIONS_OUT, true)) {',
		'replace' => 'if (in_array($cond, self::CONDITIONS_OUT, true)) {',
	],
	[
		'name' => 'due-at-upper-bound-ge',
		'file' => 'lib/Service/LoanService.php',
		'search' => 'if ($dueAt !== null && ($dueAt <= 0 || $dueAt > 4_102_444_800)) {',
		'replace' => 'if ($dueAt !== null && ($dueAt <= 0 || $dueAt >= 4_102_444_800)) {',
	],
	[
		'name' => 'returned-case-ge-becomes-gt',
		'file' => 'lib/Db/LoanMapper.php',
		'search' => '"CASE WHEN qty_returned + " . $qb->createNamedParameter($qty, IQueryBuilder::PARAM_INT) . " >= qty THEN \'returned\' ELSE \'partial\' END"',
		'replace' => '"CASE WHEN qty_returned + " . $qb->createNamedParameter($qty, IQueryBuilder::PARAM_INT) . " > qty THEN \'returned\' ELSE \'partial\' END"',
	],
	[
		'name' => 'cas-cap-lte-becomes-lt',
		'file' => 'lib/Db/LoanMapper.php',
		'search' => '->andWhere($qb->expr()->lte(',
		'replace' => '->andWhere($qb->expr()->lt(',
	],
	[
		'name' => 'anonymize-open-guard-gt-becomes-ge',
		'file' => 'lib/Service/PersonService.php',
		'search' => 'if ($this->loans->countOpenForPerson($personId) > 0) {',
		'replace' => 'if ($this->loans->countOpenForPerson($personId) >= 0) {',
	],
	[
		'name' => 'notify-dedup-negated',
		'file' => 'lib/Service/LoanDueNotifyService.php',
		'search' => 'if (!$this->notifyLog->tryInsert($key, $loan->getItemId(), $now)) {',
		'replace' => 'if ($this->notifyLog->tryInsert($key, $loan->getItemId(), $now)) {',
	],
]);
