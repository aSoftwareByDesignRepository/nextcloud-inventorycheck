<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * ArbeitszeitCheck shell parity contract for InventoryCheck.
 * Fails the build when nav hierarchy, filter panel, or page chrome drift.
 */
final class AzcShellParityContractTest extends TestCase
{
	private string $root;
	private string $azCss;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 3);
		$azNav = dirname($this->root) . '/arbeitszeitcheck/css/navigation.css';
		if (!is_file($azNav)) {
			$this->markTestSkipped('ArbeitszeitCheck navigation.css missing');
		}
		$this->azCss = (string)file_get_contents($azNav);
	}

	public function testShellFilesExist(): void
	{
		foreach ([
			'/css/common/tokens.css',
			'/css/common/app-layout.css',
			'/css/common/page-patterns.css',
			'/css/common/shell-chrome.css',
			'/css/navigation.css',
			'/js/common/navigation.js',
			'/templates/common/page-start.php',
			'/templates/common/navigation.php',
			'/templates/common/page-end.php',
		] as $rel) {
			$this->assertFileExists($this->root . $rel, $rel);
		}
	}

	public function testNavigationUsesAzcHierarchyClasses(): void
	{
		$nav = (string)file_get_contents($this->root . '/templates/common/navigation.php');
		foreach (['nav-menu', 'nav-item-has-children', 'nav-parent-toggle', 'nav-submenu', 'nav-parent-chevron', 'sidebar-header', 'app-brand'] as $token) {
			$this->assertStringContainsString($token, $nav, 'nav missing ' . $token);
		}
		$this->assertStringContainsString('iv-admin-subnav', $nav);
		$this->assertStringContainsString("aria-expanded", $nav);
	}

	public function testPageChromeMatchesAzcStructure(): void
	{
		$start = (string)file_get_contents($this->root . '/templates/common/page-start.php');
		foreach ([
			'iv-breadcrumb',
			'iv-page-header__icon',
			'iv-page-header__lead',
			'iv-scope-strip',
			'iv-live-region',
			'iv-alert-region',
			'iv-skip-link',
			'iv-main-content',
		] as $token) {
			$this->assertStringContainsString($token, $start, 'page-start missing ' . $token);
		}
		$end = (string)file_get_contents($this->root . '/templates/common/page-end.php');
		$this->assertStringContainsString('inventorycheck-app', $end);
	}

	public function testLayoutCssPinsSidebarWidthLikeAzc(): void
	{
		$layout = (string)file_get_contents($this->root . '/css/common/app-layout.css');
		$this->assertStringContainsString('#content.app-inventorycheck', $layout);
		$this->assertMatchesRegularExpression('/flex:\s*0 0 280px/', $layout);
		$this->assertStringContainsString('min-height: 0', $layout);
	}

	public function testNavigationCssKeepsSubmenuRail(): void
	{
		$css = (string)file_get_contents($this->root . '/css/navigation.css');
		$this->assertStringContainsString('.nav-submenu::before', $css);
		$this->assertStringContainsString($this->azCss !== '' ? '.nav-submenu::before' : '', $this->azCss);
		$this->assertMatchesRegularExpression(
			'/\.nav-submenu > li > a::before \{\s*content:\s*none;/s',
			$css,
		);
	}

	public function testFilterPanelAndDialogTokensPresent(): void
	{
		$patterns = (string)file_get_contents($this->root . '/css/common/page-patterns.css');
		$this->assertStringContainsString('iv-filter-panel', $patterns);
		$this->assertStringContainsString('iv-filter-grid', $patterns);
		$js = (string)file_get_contents($this->root . '/js/app.js');
		$this->assertStringContainsString('iv-filter-panel', $js);
		$this->assertStringContainsString('modal-backdrop', $js);
		$this->assertStringContainsString("setAttribute('inert'", $js);
	}

	public function testNavigationJsTogglesAriaExpandedAndHidden(): void
	{
		$js = (string)file_get_contents($this->root . '/js/common/navigation.js');
		$this->assertStringContainsString('aria-expanded', $js);
		$this->assertStringContainsString("setAttribute('hidden'", $js);
		$this->assertStringContainsString('ArrowDown', $js);
	}

	public function testNoPillRadiiRemainInAppCss(): void
	{
		$css = (string)file_get_contents($this->root . '/css/app.css');
		$this->assertStringNotContainsString('border-radius-pill', $css);
		$this->assertStringContainsString('AZ-PARITY-RADIUS-ENFORCER', $css);
		$this->assertFileExists($this->root . '/css/common/notification-surfaces.css');
		$appCss = $css;
		$this->assertStringContainsString('notification-surfaces.css', $appCss);
	}

	public function testToastUsesAzcSemanticClasses(): void
	{
		$js = (string)file_get_contents($this->root . '/js/app.js');
		$this->assertStringContainsString('toast--error', $js);
		$this->assertStringContainsString('toast--success', $js);
	}


	public function testNavigationActiveIsSolidPrimaryPillLikeAzc(): void
	{
		$css = (string)file_get_contents($this->root . '/css/navigation.css');
		$this->assertStringContainsString('solid primary pill', $css);
		$this->assertStringContainsString('color-primary-element-text', $css);
		$this->assertStringContainsString('background-color: var(--color-primary-element)', $css);
		// Legacy inset bar (pre-AZ sync) must not define active rows.
		$this->assertDoesNotMatchRegularExpression(
			'/li\.active > a[^{]*\{[^}]*inset 4px 0 0/s',
			$css,
			'Active nav must be solid primary fill, not inset bar'
		);
		$this->assertStringContainsString('nav-item-inset', $css);
	}

	public function testLayoutCssPinsMidDesktopNavWidthLikeAzc(): void
	{
		$layout = (string)file_get_contents($this->root . '/css/common/app-layout.css');
		$this->assertStringContainsString('max-width: 1280px', $layout);
		$this->assertMatchesRegularExpression('/flex:\s*0 0 240px/', $layout);
	}

	public function testShellHasNoArtificalContentMaxWidth(): void
	{
		$chrome = (string)file_get_contents($this->root . '/css/common/shell-chrome.css');
		$this->assertStringContainsString('max-width: none', $chrome);
		$app = (string)file_get_contents($this->root . '/css/app.css');
		$this->assertDoesNotMatchRegularExpression(
			'/#app-content-wrapper\.[a-z]+-shell[^{]*\{[^}]*max-width:\s*12[08]0px/s',
			$app
		);
	}


	public function testSkipLinkIsAbsolutelyPositionedOffscreen(): void
	{
		$chrome = (string)file_get_contents($this->root . '/css/common/shell-chrome.css');
		$this->assertStringContainsString('Skip links (AZ parity) — CRITICAL', $chrome);
		$this->assertMatchesRegularExpression(
			'/\.skip-link[^{]*\{[^}]*position:\s*absolute\s*!important/s',
			$chrome,
			'Nav skip-link MUST be absolute or it becomes a flex column and ruins layout'
		);
		$this->assertMatchesRegularExpression(
			'/\.skip-link[^{]*\{[^}]*left:\s*-9999px/s',
			$chrome
		);
		$nav = (string)file_get_contents($this->root . '/templates/common/navigation.php');
		$this->assertStringContainsString('skip-link', $nav);
		$this->assertStringContainsString('Skip to app navigation', $nav);
	}

	public function testTableChromeMatchesAzcBaseStyles(): void
	{
		$chrome = (string)file_get_contents($this->root . '/css/common/shell-chrome.css');
		$this->assertStringContainsString('AZ table parity', $chrome);
		$this->assertMatchesRegularExpression(
			'/th[^{]*\{[^}]*font-weight:\s*600/s',
			$chrome
		);
		$this->assertMatchesRegularExpression(
			'/th[^{]*\{[^}]*padding:\s*var\(/s',
			$chrome
		);
	}

}
