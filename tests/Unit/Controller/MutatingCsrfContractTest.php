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
		return [
			$root . '/ItemController.php',
			$root . '/LocationController.php',
			$root . '/MovementController.php',
			$root . '/ConfigController.php',
			$root . '/LicenseController.php',
			$root . '/BalanceController.php',
			$root . '/LowStockController.php',
		];
	}

	public function testMutatingMethodsAreNotCsrfExempt(): void
	{
		$mutating = [
			'create', 'update', 'destroy',
			'receive', 'issue', 'transfer', 'adjust', 'scan',
			'saveAccess', 'saveOffice',
			'apply', 'remove', 'assignSeat', 'removeSeat',
			'createDevice', 'regeneratePairCode', 'removeDevice',
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
