<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * P9 — settings template must instantiate SupportUsLinks for the partial.
 */
final class SettingsSupportUsWiringTest extends TestCase
{
	public function testSettingsTemplatePassesSupportUsLinksInstance(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/templates/settings.php');
		self::assertStringContainsString('SupportUsLinks', $src);
		self::assertStringContainsString('$supportUsLinks', $src);
		self::assertStringContainsString("supportUsCssPrefix = 'iv'", $src);
		self::assertStringContainsString('parts/support-us-section.php', $src);
		self::assertStringNotContainsString("\$supportUs = \$_['supportUs']", $src);
	}
}
