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
		foreach (['nav-menu', 'sidebar-header', 'app-brand'] as $token) {
			$this->assertStringContainsString($token, $nav, 'nav missing ' . $token);
		}
		// Design-system: Settings expands to section sub-pages (SETTINGS-PAGES-STANDARD).
		$this->assertStringContainsString("l->t('Settings')", $nav);
		$this->assertStringContainsString('nav-item-has-children', $nav);
		$this->assertStringContainsString('iv-settings-subnav', $nav);
		$this->assertStringContainsString('nav-submenu', $nav);
		$this->assertStringContainsString('settingsSectionUrls', $nav);
		$this->assertStringContainsString('settingsSectionLabels', $nav);
		// DutyCheck / design-system: visible hint lines under nav labels (not title-only).
		$this->assertStringContainsString('iv-nav__label', $nav);
		$this->assertStringContainsString('iv-nav__name', $nav);
		$this->assertStringContainsString('iv-nav__hint', $nav);
		$this->assertStringContainsString("l->t('Low stock and recent bookings')", $nav);
		$this->assertStringContainsString("l->t('Access, license, support')", $nav);
		$start = (string)file_get_contents($this->root . '/templates/common/page-start.php');
		$this->assertStringContainsString("navUrls['settings']", $start);
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
			'data-iv-is-system-admin',
			'data-iv-require-adjust-reason',
			'data-iv-require-location-scan',
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
		// Visible name+hint must not inherit nowrap ellipsis from bare spans.
		$this->assertStringContainsString(':not(.iv-nav__label)', $css);
		$this->assertStringContainsString('.iv-nav__hint', $css);
		// Active primary pill: hints must use on-fill text (not maxcontrast) for every NC theme.
		$this->assertStringContainsString('a[aria-current="page"] .iv-nav__hint', $css);
		$this->assertMatchesRegularExpression(
			'/a\[aria-current="page"\]\s+\.iv-nav__hint[\s\S]{0,800}?color:\s*var\(--color-primary-element-text\)/s',
			$css,
		);
		$this->assertStringContainsString('Maxcontrast hints stay dark-on-dark', $css);
		// Mobile drawer must keep NC $navigation-width — width:100% on the rail leaves a white slab.
		$this->assertStringContainsString('never set width:100% on #app-navigation', $css);
		$this->assertStringContainsString('--navigation-width', $css);
		$this->assertDoesNotMatchRegularExpression(
			'/#content\.app-inventorycheck #app-navigation\s*\{[^}]*width:\s*100%/s',
			$css,
			'#app-navigation must not force width:100% (breaks NC mobile drawer translate)'
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
		// Stack page-header actions below the title (DutyCheck: always full-width row).
		$this->assertStringContainsString('grid-column: 1 / -1', $chrome);
		$this->assertMatchesRegularExpression(
			'/\.iv-page-header__actions\s*\{[^}]*grid-column:\s*1\s*\/\s*-1/s',
			$chrome,
			'Page-header actions must be a full-width row under the title (never a 3rd column)'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/\.iv-page-header__main\s*\{[^}]*grid-template-columns:\s*[^}]*\s+auto/s',
			$chrome,
			'Page-header must not use a 3-column icon|title|actions grid'
		);
		$this->assertMatchesRegularExpression(
			'/\.iv-page-header__main\s*\{[^}]*grid-template-columns:\s*56px\s+minmax\(0,\s*1fr\)/s',
			$chrome,
		);
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


	public function testFormControlsCssIsImportedAndScoped(): void
	{
		$appCss = (string)file_get_contents($this->root . '/css/app.css');
		$this->assertStringContainsString('form-controls.css', $appCss);
		$path = $this->root . '/css/common/form-controls.css';
		$this->assertFileExists($path);
		$css = (string)file_get_contents($path);
		$this->assertStringContainsString('min-height: 44px', $css);
		$this->assertStringContainsString('accent-color: var(--color-primary-element)', $css);
		$this->assertStringContainsString('input[type="checkbox"]', $css);
		$this->assertStringContainsString('select', $css);
		$this->assertStringContainsString('box-shadow: 0 0 0 3px', $css);
		$this->assertStringContainsString('[role="combobox"]', $css);
		// Must stay scoped — never style NC global chrome
		$this->assertStringContainsString('#content.app-', $css);
		$this->assertDoesNotMatchRegularExpression('/^input\\s*,/m', $css);
	}


	public function testContentSurfacesMatchAzcCardsAndButtons(): void
	{
		$chrome = (string)file_get_contents($this->root . '/css/common/shell-chrome.css');
		$this->assertStringContainsString('AZ content surfaces', $chrome);
		$this->assertStringContainsString('-section', $chrome);
		$this->assertStringContainsString('-filter-panel', $chrome);
		$this->assertMatchesRegularExpression('/border-radius:\s*var\(--[a-z]+-radius-md,\s*12px\)/', $chrome);
		$app = (string)file_get_contents($this->root . '/css/app.css');
		$this->assertStringContainsString('AZ-PARITY-RADIUS-ENFORCER', $app);
		// Primary buttons must use md (12px) like azc-btn — not sm.
		$this->assertMatchesRegularExpression(
			'/\.button[^{]*\{[^}]*border-radius:\s*var\(--[a-z]+-radius-md,\s*12px\)\s*!important/s',
			$app
		);
		// Body-mounted dialogs must use lg (16px) — #content-scoped rules never apply.
		$this->assertMatchesRegularExpression(
			'/body\s*>\s*\.modal-backdrop\s*>\s*\.modal[^{]*\{[^}]*border-radius:\s*var\(--[a-z]+-radius-lg,\s*16px\)\s*!important/s',
			$app,
			'Dialogs mount on body; radius enforcer must cover modal-backdrop > .modal'
		);
	}

	/**
	 * Bachus: structured list cards must beat AZ content-surface padding so
	 * headers share Filter soft-band chrome (no inset white trap).
	 */
	public function testStructuredCardsMatchFilterPanelChromeSpecificity(): void
	{
		$chrome = (string)file_get_contents($this->root . '/css/common/shell-chrome.css');
		$this->assertMatchesRegularExpression(
			'/#content\.app-inventorycheck\s+#app-content\s+\.iv-card:has\(\.iv-card__header\)[\s\S]*?padding:\s*0/s',
			$chrome,
			'Structured list cards need padding:0 at #content specificity (Filter parity)'
		);
		$this->assertMatchesRegularExpression(
			'/#content\.app-inventorycheck\s+#app-content\s+\.iv-card__header[\s\S]*?background:\s*var\(--iv-bg-soft/s',
			$chrome,
			'List card headers must share Filter soft-band chrome'
		);
		$this->assertStringContainsString('Bachus / AZ parity: structured cards', $chrome);
	}

}
