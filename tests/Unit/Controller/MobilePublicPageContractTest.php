<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * AC-17 / AC-18 / §9.3 — device token callers must reach MobileController
 * without NC session or CSRF (PublicPage + NoCSRFRequired on every route).
 */
final class MobilePublicPageContractTest extends TestCase
{
	public function testEveryMobileMethodIsPublicAndCsrfExempt(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/MobileController.php');
		foreach (['bootstrap', 'byCode', 'locationByCode', 'locations', 'balances', 'movements', 'scan', 'pairDevice'] as $method) {
			$this->assertMatchesRegularExpression(
				'/\#\[PublicPage\]\s*(?:\#\[[^\]]+\]\s*)*public function ' . preg_quote($method, '/') . '\b/s',
				$src,
				$method . ' must be #[PublicPage]',
			);
			$this->assertMatchesRegularExpression(
				'/\#\[NoCSRFRequired\]\s*(?:\#\[[^\]]+\]\s*)*public function ' . preg_quote($method, '/') . '\b/s',
				$src,
				$method . ' must be #[NoCSRFRequired]',
			);
			$this->assertMatchesRegularExpression(
				'/\#\[NoAdminRequired\]\s*(?:\#\[[^\]]+\]\s*)*public function ' . preg_quote($method, '/') . '\b/s',
				$src,
				$method . ' must be #[NoAdminRequired] (device/session callers are not NC admins)',
			);
		}
		$this->assertStringContainsString('touchLastSeen', $src);
		$this->assertStringContainsString('X-IV-Device-Token', $src);
		// SPEC §9.1: bootstrap skips rungs 3–6 only — still requires auth.
		$this->assertMatchesRegularExpression(
			'/function bootstrap\(\)[\s\S]*?resolveCaller\(true\)/',
			$src,
		);
		$this->assertStringContainsString("auth_required", $src);
	}
}
