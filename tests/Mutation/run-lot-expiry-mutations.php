#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/harness.php';

// IV-LOT-EXPIRY-PACK — the three flip-prone lines of the spec:
// (a) the `<= now + warn` window boundary, (b) the `qty > 0` stock filter,
// (c) the NULLS-LAST expiry ordering in the FEFO sort.
runMutations(dirname(__DIR__, 2), 'LotServiceTest', [
	[
		'name' => 'expired-boundary-becomes-inclusive',
		'file' => 'lib/Service/LotService.php',
		'search' => 'if ($expiresAt < $now) {',
		'replace' => 'if ($expiresAt <= $now) {',
	],
	[
		'name' => 'expiring-window-becomes-exclusive',
		'file' => 'lib/Service/LotService.php',
		'search' => 'if ($warnSec > 0 && $expiresAt <= $now + $warnSec) {',
		'replace' => 'if ($warnSec > 0 && $expiresAt < $now + $warnSec) {',
	],
	[
		'name' => 'empty-filter-drops-zero-qty-check',
		'file' => 'lib/Service/LotService.php',
		'search' => 'if ($qty <= 0) {',
		'replace' => 'if ($qty < 0) {',
	],
	[
		'name' => 'fefo-nulls-first',
		'file' => 'lib/Service/LotService.php',
		'search' => 'return $aNull <=> $bNull;',
		'replace' => 'return $bNull <=> $aNull;',
	],
	[
		'name' => 'expiring-card-keeps-zero-qty',
		'file' => 'lib/Service/LotService.php',
		'search' => 'if ($qty <= 0) {
				continue; // NN-04: zero-qty noise never reaches the card
			}',
		'replace' => 'if ($qty < 0) {
				continue; // NN-04: zero-qty noise never reaches the card
			}',
	],
]);
