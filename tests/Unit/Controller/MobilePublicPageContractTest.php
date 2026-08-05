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
		foreach ([
			'bootstrap', 'byCode', 'itemPhoto', 'locationByCode', 'locations', 'balances', 'movements', 'scan', 'pairDevice',
			'favourites', 'addFavourite', 'removeFavourite', 'cycleCounts', 'cycleCountShow', 'cycleCountSetCount',
		] as $method) {
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
		$this->assertStringContainsString('ItemPhotoService', $src);
		$this->assertStringContainsString('DataDisplayResponse', $src);
		// Session mutations must not accept a bare cookie (device token OR CSRF).
		$this->assertStringContainsString('assertSafeMutationChannel', $src);
		$this->assertStringContainsString('passesCSRFCheck', $src);
		$this->assertStringContainsString(
			"if (\$this->request->passesCSRFCheck()) {",
			$src,
			'CSRF must be required — not short-circuited with true ||',
		);
		$this->assertStringNotContainsString('true || $this->request->passesCSRFCheck()', $src);
		// Forged Authorization must never bypass CSRF (resolveCaller ignores it).
		$this->assertDoesNotMatchRegularExpression(
			'/Basic\|Bearer/',
			$src,
			'Authorization Basic/Bearer must not short-circuit assertSafeMutationChannel',
		);
		foreach (['scan', 'addFavourite', 'removeFavourite', 'cycleCountSetCount'] as $mutating) {
			$this->assertMatchesRegularExpression(
				'/function ' . preg_quote($mutating, '/') . '\([^\)]*\)\s*:\s*JSONResponse\s*\{[\s\S]{0,500}?assertSafeMutationChannel\(\)/',
				$src,
				$mutating . ' must call assertSafeMutationChannel before work',
			);
		}
		// SPEC §9.1: bootstrap skips rungs 3–6 only — still requires auth.
		$this->assertMatchesRegularExpression(
			'/function bootstrap\(\)[\s\S]*?resolveCaller\(true\)/',
			$src,
		);
		$this->assertStringContainsString("auth_required", $src);

		$routes = (string)file_get_contents(dirname(__DIR__, 3) . '/appinfo/routes.php');
		$this->assertStringContainsString("'name' => 'mobile#itemPhoto'", $routes);
		$this->assertStringContainsString('/mobile/v1/items/{id}/photo', $routes);
	}
}
