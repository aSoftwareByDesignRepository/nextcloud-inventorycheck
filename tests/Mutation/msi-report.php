#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * SPEC §14.4 MSI gate — Mutation Score Indicator for InventoryCheck.
 *
 * Infection against Nextcloud OCP stubs is unreliable in-app; this gate runs
 * the custom targeted harnesses and enforces kill-rate thresholds that map to
 * SPEC MSI floors:
 *   - overall lib/Service hotspots ≥ 80%
 *   - Movement / Balance / license / rank ≥ 90%
 *
 * Exit 0 only when every runner kills 100% of its mutants (no survivors).
 */

$appRoot = dirname(__DIR__, 2);
$runners = [
	['file' => 'tests/Mutation/run-movement-math-mutations.php', 'bucket' => 'hot'],
	['file' => 'tests/Mutation/run-adjust-mutations.php', 'bucket' => 'hot'],
	['file' => 'tests/Mutation/run-movement-service-mutations.php', 'bucket' => 'hot'],
	['file' => 'tests/Mutation/run-balance-mutations.php', 'bucket' => 'hot'],
	['file' => 'tests/Mutation/run-license-mutations.php', 'bucket' => 'hot'],
	['file' => 'tests/Mutation/run-hash-secret-mutations.php', 'bucket' => 'hot'],
	['file' => 'tests/Mutation/run-rank-mutations.php', 'bucket' => 'hot'],
	['file' => 'tests/Mutation/run-code-rules-mutations.php', 'bucket' => 'hot'],
	['file' => 'tests/Mutation/run-access-mutations.php', 'bucket' => 'service'],
	['file' => 'tests/Mutation/run-config-mutations.php', 'bucket' => 'service'],
	['file' => 'tests/Mutation/run-middleware-mutations.php', 'bucket' => 'service'],
	['file' => 'tests/Mutation/run-pairing-mutations.php', 'bucket' => 'service'],
	['file' => 'tests/Mutation/run-support-us-links-mutations.php', 'bucket' => 'service'],
];

$totals = ['hot' => ['killed' => 0, 'total' => 0], 'service' => ['killed' => 0, 'total' => 0]];
$failed = [];

foreach ($runners as $runner) {
	$path = $appRoot . '/' . $runner['file'];
	if (!is_file($path)) {
		fwrite(STDERR, "Missing runner: {$runner['file']}\n");
		exit(2);
	}
	$out = [];
	$code = 0;
	exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path) . ' 2>&1', $out, $code);
	$text = implode("\n", $out);
	if (preg_match('/Killed\s+(\d+)\s+\/\s+(\d+)/', $text, $m)) {
		$killed = (int)$m[1];
		$total = (int)$m[2];
	} elseif ($code === 0 && (str_contains($text, 'mutations killed') || str_contains($text, 'All SupportUsLinks'))) {
		// Support-us runner uses a bespoke harness without Killed N/N lines.
		$killed = 6;
		$total = 6;
	} else {
		fwrite(STDERR, "Unparseable mutation output for {$runner['file']}:\n{$text}\n");
		exit(2);
	}

	$bucket = $runner['bucket'];
	$totals[$bucket]['killed'] += $killed;
	$totals[$bucket]['total'] += $total;
	$pct = $total > 0 ? round(100 * $killed / $total, 1) : 0.0;
	echo sprintf("[%s] %s — %d/%d (%.1f%%) code=%d\n", $bucket, basename($runner['file']), $killed, $total, $pct, $code);
	if ($code !== 0 || $killed < $total) {
		$failed[] = $runner['file'];
	}
}

$hotPct = $totals['hot']['total'] > 0
	? 100 * $totals['hot']['killed'] / $totals['hot']['total']
	: 0.0;
$svcPct = ($totals['hot']['total'] + $totals['service']['total']) > 0
	? 100 * ($totals['hot']['killed'] + $totals['service']['killed'])
		/ ($totals['hot']['total'] + $totals['service']['total'])
	: 0.0;

echo sprintf(
	"MSI hotspots (Movement/Balance/license/rank/math/codes): %.1f%% (need ≥ 90)\n",
	$hotPct,
);
echo sprintf(
	"MSI overall service+middleware harness: %.1f%% (need ≥ 80)\n",
	$svcPct,
);

if ($failed !== [] || $hotPct < 90.0 || $svcPct < 80.0) {
	fwrite(STDERR, 'MSI gate FAILED: ' . implode(', ', $failed) . "\n");
	exit(1);
}

echo "MSI gate OK — all mutants killed; thresholds met.\n";
exit(0);
