<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Browser page navigations never send requesttoken — every PageController
 * action must be #[NoCSRFRequired] (same contract as MaintenanceCheck).
 * Mutating JSON APIs stay CSRF-protected on purpose.
 */
final class PageNoCsrfContractTest extends TestCase
{
	public function testEveryPageActionIsNoCsrfRequired(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/PageController.php');
		foreach (['dashboard', 'items', 'item', 'locations', 'location', 'movements', 'settings', 'settingsSection'] as $method) {
			self::assertMatchesRegularExpression(
				'/\#\[NoCSRFRequired\]\s*(?:\#\[[^\]]+\]\s*)*public function ' . preg_quote($method, '/') . '\b/s',
				$src,
				$method . ' must be #[NoCSRFRequired] for browser GET navigation',
			);
		}
	}

	public function testLabelBrowserEndpointsAreNoCsrfRequired(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/ItemController.php');
		foreach (['label', 'labelAlias', 'labelPrint'] as $method) {
			self::assertMatchesRegularExpression(
				'/\#\[NoCSRFRequired\]\s*(?:\#\[[^\]]+\]\s*)*public function ' . preg_quote($method, '/') . '\b/s',
				$src,
				$method . ' must be #[NoCSRFRequired] for browser GET / download',
			);
		}
	}
}
