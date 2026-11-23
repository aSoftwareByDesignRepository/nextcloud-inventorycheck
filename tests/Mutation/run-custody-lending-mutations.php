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
	// Wave B — seat/device paths + private evidence: signature cap/whitelist,
	// uniform-404 visibility, seat-scoped open-loan filter, person-only search.
	[
		'name' => 'signature-cap-shrinks',
		'file' => 'lib/Service/LoanEvidenceService.php',
		'search' => 'public const SIG_MAX_BYTES = 262_144;',
		'replace' => 'public const SIG_MAX_BYTES = 64;',
	],
	[
		'name' => 'signature-dim-floor-raised',
		'file' => 'lib/Service/LoanEvidenceService.php',
		'search' => "(string)\$info['mime'] !== 'image/png' || \$info[0] < 16 || \$info[1] < 16",
		'replace' => "(string)\$info['mime'] !== 'image/png' || \$info[0] < 32 || \$info[1] < 16",
	],
	[
		'name' => 'evidence-visibility-negated',
		'file' => 'lib/Service/LoanEvidenceService.php',
		'search' => 'if (!$this->locationAcl->canAccessLocation($actorUid, $loan->getLocationId())) {',
		'replace' => 'if ($this->locationAcl->canAccessLocation($actorUid, $loan->getLocationId())) {',
	],
	[
		'name' => 'read-mime-signature-flip',
		'file' => 'lib/Service/LoanEvidenceService.php',
		'search' => "\$mime = \$which === 'signature' ? 'image/png' :",
		'replace' => "\$mime = \$which === 'signature' ? 'application/octet-stream' :",
	],
	[
		'name' => 'open-for-seat-membership-negated',
		'file' => 'lib/Service/LoanService.php',
		'search' => 'fn (Loan $l): bool => $visible === null || in_array($l->getLocationId(), $visible, true),',
		'replace' => 'fn (Loan $l): bool => $visible === null || !in_array($l->getLocationId(), $visible, true),',
	],
	[
		'name' => 'seat-search-type-flip',
		'file' => 'lib/Service/PersonService.php',
		'search' => "\t\t\t\t'type' => 'person',\n\t\t\t\t'person' => \$p->toApi(),\n\t\t\t], array_slice(\$persons, 0, self::SEARCH_LIMIT)),",
		'replace' => "\t\t\t\t'type' => 'ncUser',\n\t\t\t\t'person' => \$p->toApi(),\n\t\t\t], array_slice(\$persons, 0, self::SEARCH_LIMIT)),",
	],
]);
