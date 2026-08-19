<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Mutating JSON APIs must stay CSRF-protected (browser session callers).
 * Page/label GETs and Mobile PublicPage routes are the only NoCSRFRequired surfaces.
 */
final class MutatingCsrfContractTest extends TestCase
{
	/** @return list<string> */
	private function controllerFiles(): array
	{
		$root = dirname(__DIR__, 3) . '/lib/Controller';
		$files = glob($root . '/*.php');
		self::assertNotFalse($files);
		$out = [];
		foreach ($files as $file) {
			if (basename($file) === 'MobileController.php') {
				continue;
			}
			$out[] = $file;
		}
		return $out;
	}

	public function testMutatingMethodsAreNotCsrfExempt(): void
	{
		$mutating = [
			'create', 'update', 'destroy',
			'receive', 'issue', 'transfer', 'adjust', 'scan',
			'saveAccess', 'saveOffice', 'saveNotify', 'saveFractional', 'saveWaveD', 'saveLocationAcl',
			'apply', 'remove', 'assignSeat', 'removeSeat',
			'createDevice', 'regeneratePairCode', 'removeDevice',
			'start', 'setCount', 'close',
			'dryRun', 'commit',
			'upload',
			'issueMaintWo', 'issueProject', 'saveSettings',
		];

		foreach ($this->controllerFiles() as $file) {
			$src = (string)file_get_contents($file);
			$base = basename($file);
			foreach ($mutating as $method) {
				if (!preg_match('/public function ' . preg_quote($method, '/') . '\b/', $src)) {
					continue;
				}
				$this->assertDoesNotMatchRegularExpression(
					'/\#\[NoCSRFRequired\]\s*(?:\#\[[^\]]+\]\s*)*public function ' . preg_quote($method, '/') . '\b/s',
					$src,
					"$base::$method must NOT be #[NoCSRFRequired]",
				);
			}
		}
	}

	public function testLabelAndPageGetsRemainCsrfExempt(): void
	{
		$page = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/PageController.php');
		$this->assertMatchesRegularExpression(
			'/\#\[NoCSRFRequired\]\s*(?:\#\[[^\]]+\]\s*)*public function dashboard\b/s',
			$page,
		);
		$item = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/ItemController.php');
		$this->assertMatchesRegularExpression(
			'/\#\[NoCSRFRequired\]\s*(?:\#\[[^\]]+\]\s*)*public function labelPrint\b/s',
			$item,
		);
	}
}
