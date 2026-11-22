#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/harness.php';

// IV-LOT-EXPIRY-PACK job — qty gate and watch-off guard.
runMutations(dirname(__DIR__, 2), 'LotExpiryNotifyServiceTest', [
	[
		'name' => 'job-keeps-zero-qty-lots',
		'file' => 'lib/Service/LotExpiryNotifyService.php',
		'search' => 'if ($qty <= 0) {',
		'replace' => 'if ($qty < 0) {',
	],
	[
		'name' => 'job-ignores-watch-off',
		'file' => 'lib/Service/LotExpiryNotifyService.php',
		'search' => 'if ($days === 0) {',
		'replace' => 'if ($days === -1) {',
	],
]);
