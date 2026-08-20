<?php

declare(strict_types=1);

/**
 * Shared targeted mutation harness (no xdebug/pcov required).
 *
 * Each runner defines mutants as literal search/replace pairs against one or
 * more source files, then calls runMutations(). A mutant is "killed" when the
 * filtered PHPUnit run fails while the mutant is applied. Originals are always
 * restored (shutdown handler covers fatals/CTRL-C).
 *
 * @param list<array{name: string, file: string, search: string, replace: string}> $mutants
 */
function runMutations(string $appRoot, string $testFilter, array $mutants): never
{
	$phpunit = $appRoot . '/vendor/bin/phpunit';
	$config = $appRoot . '/phpunit.xml';

	/** @var array<string, string> $originals */
	$originals = [];
	foreach ($mutants as $mutant) {
		$path = $appRoot . '/' . $mutant['file'];
		if (!isset($originals[$path])) {
			$content = file_get_contents($path);
			if ($content === false) {
				fwrite(STDERR, "Cannot read {$path}\n");
				exit(2);
			}
			$originals[$path] = $content;
		}
	}

	register_shutdown_function(static function () use ($originals): void {
		foreach ($originals as $path => $content) {
			file_put_contents($path, $content);
		}
	});

	// Baseline must be green before any mutant is applied — catches leftover
	// inverted operators / partial restores from a previous aborted run.
	$baselineCmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($config)
		. ' --filter ' . escapeshellarg($testFilter)
		. ' 2>&1';
	$baselineAttempts = 3;
	$baselineOut = [];
	$baselineCode = 1;
	for ($attempt = 1; $attempt <= $baselineAttempts && $baselineCode !== 0; $attempt++) {
		$baselineOut = [];
		exec($baselineCmd, $baselineOut, $baselineCode);
	}
	if ($baselineCode !== 0) {
		fwrite(STDERR, "BASELINE FAILED for filter {$testFilter} — refusing to mutate.\n");
		fwrite(STDERR, implode("\n", $baselineOut) . "\n");
		exit(2);
	}

	$killed = 0;
	$survived = [];

	foreach ($mutants as $mutant) {
		$path = $appRoot . '/' . $mutant['file'];
		$original = $originals[$path];
		$mutated = str_replace($mutant['search'], $mutant['replace'], $original);
		if ($mutated === $original) {
			fwrite(STDERR, "MISS (search string not found): {$mutant['name']}\n");
			$survived[] = $mutant['name'] . ' (miss)';
			continue;
		}
		file_put_contents($path, $mutated);
		$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phpunit)
			. ' -c ' . escapeshellarg($config)
			. ' --filter ' . escapeshellarg($testFilter)
			. ' 2>&1';
		$out = [];
		exec($cmd, $out, $code);
		file_put_contents($path, $original);
		if ($code === 0) {
			$survived[] = $mutant['name'];
			fwrite(STDERR, "SURVIVED: {$mutant['name']}\n");
		} else {
			$killed++;
			echo "Killed: {$mutant['name']}\n";
		}
	}

	echo "Killed {$killed} / " . count($mutants) . "\n";
	if ($survived !== []) {
		fwrite(STDERR, 'Surviving mutants: ' . implode(', ', $survived) . "\n");
	}
	exit($survived === [] ? 0 : 1);
}
