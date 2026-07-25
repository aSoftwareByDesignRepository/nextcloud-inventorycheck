<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

/**
 * Shared dual-process race harness for MariaDB lock tests.
 */
trait DualProcessRaceTrait
{
	/**
	 * @param callable(string $resultFile, string $goFile, string $root): string $workerSourceFactory
	 * @return array{0: string, 1: string} result strings from workers A and B
	 */
	protected function runDualWorkers(callable $workerSourceFactory): array
	{
		$root = dirname(__DIR__, 4);
		if (!is_file($root . '/lib/base.php')) {
			$root = '/var/www/html';
		}
		$this->assertFileExists($root . '/lib/base.php');

		$dir = sys_get_temp_dir() . '/iv-race-' . bin2hex(random_bytes(4));
		mkdir($dir, 0700);
		$goFile = $dir . '/go';
		$resultA = $dir . '/a.txt';
		$resultB = $dir . '/b.txt';
		$scriptA = $dir . '/worker-a.php';
		$scriptB = $dir . '/worker-b.php';

		file_put_contents($scriptA, $workerSourceFactory($resultA, $goFile, $root));
		file_put_contents($scriptB, $workerSourceFactory($resultB, $goFile, $root));

		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];
		$pA = proc_open('php ' . escapeshellarg($scriptA), $descriptors, $pipesA);
		$pB = proc_open('php ' . escapeshellarg($scriptB), $descriptors, $pipesB);
		$this->assertIsResource($pA);
		$this->assertIsResource($pB);
		foreach (array_merge($pipesA, $pipesB) as $pipe) {
			fclose($pipe);
		}

		usleep(150000);
		file_put_contents($goFile, '1');

		proc_close($pA);
		proc_close($pB);

		$outA = is_file($resultA) ? trim((string)file_get_contents($resultA)) : 'missing';
		$outB = is_file($resultB) ? trim((string)file_get_contents($resultB)) : 'missing';

		foreach ([$scriptA, $scriptB, $resultA, $resultB, $goFile] as $f) {
			if (is_file($f)) {
				unlink($f);
			}
		}
		@rmdir($dir);

		return [$outA, $outB];
	}
}
