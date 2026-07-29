<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * Filter panels must match ArbeitszeitCheck:
 * - Items/Locations: simple search + actions grid
 * - Movements: manager-scope criteria + date range
 */
final class FilterPanelLayoutContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 3);
	}

	public function testAppJsBuildsAzcFilterPanelsForItemsLocationsAndMovements(): void
	{
		$js = (string)file_get_contents($this->root . '/js/app.js');
		foreach ([
			"className: 'iv-card iv-filter-panel'",
			'iv-filter-panel__head',
			'iv-filter-panel__body',
			'iv-filter-panel__form',
			'iv-filter-grid',
			'iv-filter-grid--simple',
			'iv-filter-field__label',
			'iv-filter-field__control',
			'iv-filter-field--actions',
			'iv-filter-field--search',
			'iv-filter-field__control--actions',
			'iv-filter-field--kind',
			'iv-filter-field--item',
			'iv-filter-field--location',
			'iv-filter-field--dates',
			'iv-items-filter-title',
			'iv-items-filter-panel',
			'iv-loc-filter-title',
			'iv-loc-filter-panel',
			'iv-mov-filter-title',
			'iv-filter-grid--movements',
			'iv-date-range',
			'iv-date-range__part',
			'iv-date-range__sep',
			'iv-mov-date-range-label',
			'iv-mov-from',
			'iv-mov-to',
			'No items match these filters',
			'No locations match these filters',
			'loadSeq',
			"role: 'group'",
			"tr('Filter options')",
			"tr('Apply')",
			"tr('Date range')",
			"tr('to')",
			'form-select',
			'form-input',
		] as $token) {
			$this->assertStringContainsString($token, $js, 'app.js missing ' . $token);
		}
		$this->assertStringContainsString('resolveMasterId', $js);
		$this->assertStringContainsString('hydrateMaps', $js);
		$this->assertStringContainsString('SKU or name', $js);
		$this->assertStringContainsString("type: 'search'", $js);
		$this->assertStringContainsString('iv-callout iv-callout--info iv-filter-active', $js);
		$this->assertStringContainsString('kindSelect.addEventListener(\'change\'', $js);
		$this->assertStringNotContainsString("className: 'iv-filter-bar'", $js);
		$this->assertStringNotContainsString('iv-filter-grid--extended', $js);
		$this->assertDoesNotMatchRegularExpression(
			"/className:\s*'iv-toolbar'/",
			$js,
			'items must not fall back to bare iv-toolbar'
		);
	}

	public function testLegacyFilterbarStylesDoNotApplyInsidePanels(): void
	{
		$css = (string)file_get_contents($this->root . '/css/app.css');
		$this->assertStringContainsString('.iv-filterbar:not(.iv-filter-panel__form)', $css);
		$this->assertStringContainsString('.iv-filter-panel .iv-filter-panel__form.iv-filterbar', $css);
		$this->assertStringContainsString('background: transparent', $css);
		$this->assertStringContainsString('.iv-filter-active', $css);
		$this->assertStringContainsString('grid-template-areas', $css);
		$this->assertStringContainsString("'kind item location actions'", $css);
		$this->assertStringContainsString("'dates dates dates dates'", $css);
		$this->assertStringContainsString("'search actions'", $css);
		$this->assertStringContainsString('iv-filter-grid--simple', $css);
		// Standalone legacy rule must not style panel forms via bare .iv-filterbar { padding }.
		$this->assertDoesNotMatchRegularExpression(
			'/^\.iv-filterbar\s*\{/m',
			$css,
			'bare .iv-filterbar block would fight AZ panel chrome'
		);
	}

	public function testPagePatternsKeepFilterGridTouchTargets(): void
	{
		$patterns = (string)file_get_contents($this->root . '/css/common/page-patterns.css');
		$this->assertStringContainsString('iv-filter-panel', $patterns);
		$this->assertStringContainsString('iv-filter-grid', $patterns);
		$this->assertStringContainsString('iv-filter-grid--movements', $patterns);
		$this->assertStringContainsString('iv-filter-grid--simple', $patterns);
		$this->assertStringContainsString('iv-filter-field__control--actions', $patterns);
		$this->assertStringContainsString('iv-date-range', $patterns);
		$this->assertStringContainsString('min-height: 44px', $patterns);
		$this->assertStringContainsString('align-items: stretch', $patterns);
		$this->assertStringContainsString('@media (max-width: 768px)', $patterns);
		$this->assertStringContainsString('@media (max-width: 1200px)', $patterns);
		$this->assertStringContainsString('.iv-filter-grid.iv-filter-grid--movements', $patterns);
		$this->assertStringContainsString('grid-template-areas', $patterns);
	}

	public function testLocationApiAcceptsSearchQuery(): void
	{
		$controller = (string)file_get_contents($this->root . '/lib/Controller/LocationController.php');
		$service = (string)file_get_contents($this->root . '/lib/Service/LocationService.php');
		$mapper = (string)file_get_contents($this->root . '/lib/Db/LocationMapper.php');
		$this->assertStringContainsString("getParam('q'", $controller);
		$this->assertStringContainsString('string $q = \'\'', $service);
		$this->assertStringContainsString('string $q = \'\'', $mapper);
		$this->assertStringContainsString("escapeLikeParameter", $mapper);
		$this->assertStringContainsString("like('code'", $mapper);
	}
}
