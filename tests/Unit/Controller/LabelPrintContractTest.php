<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * UJ-1 / A12 — printable label route + blank template contracts.
 */
final class LabelPrintContractTest extends TestCase
{
	public function testRoutesExposeSvgDownloadAndPrintView(): void
	{
		$routes = (string)file_get_contents(dirname(__DIR__, 3) . '/appinfo/routes.php');
		self::assertStringContainsString("'name' => 'item#label'", $routes);
		self::assertStringContainsString('/api/items/{id}/label.svg', $routes);
		// SPEC §7.3 canonical path must also resolve.
		self::assertStringContainsString("'name' => 'item#labelAlias'", $routes);
		self::assertStringContainsString("'url' => '/api/items/{id}/label',", $routes);
		self::assertStringContainsString("'name' => 'item#labelPrint'", $routes);
		self::assertStringContainsString('/items/{id}/label', $routes);
	}

	public function testPageBootstrapExposesLabelUrls(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/PageController.php');
		self::assertStringContainsString("'itemLabel'", $src);
		self::assertStringContainsString("'itemLabelPrint'", $src);
		self::assertStringContainsString('inventorycheck.item.labelPrint', $src);
	}

	public function testLabelPrintTemplateIsBlankPrintFriendly(): void
	{
		$tpl = (string)file_get_contents(dirname(__DIR__, 3) . '/templates/label-print.php');
		self::assertStringContainsString('RENDER_AS_BLANK', (string)file_get_contents(
			dirname(__DIR__, 3) . '/lib/Controller/ItemController.php',
		));
		self::assertStringContainsString('@media print', $tpl);
		self::assertStringContainsString('iv-label-print__toolbar', $tpl);
		self::assertStringContainsString('window.print()', $tpl);
		self::assertStringContainsString("\$l->t('Print label')", $tpl);
		self::assertStringContainsString("\$l->t('Download SVG')", $tpl);
		self::assertStringContainsString('print_unescaped($svg)', $tpl);
	}

	public function testItemControllerLabelMethodsExist(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/ItemController.php');
		self::assertStringContainsString('function label(int $id)', $src);
		self::assertStringContainsString('function labelPrint(int $id)', $src);
		self::assertStringContainsString('DataDownloadResponse', $src);
		self::assertStringContainsString('LabelSvg::forItem', $src);
	}

	public function testLocationBulkLabelsPassesLabelsHtmlMatchingTemplate(): void
	{
		$controller = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/LocationController.php');
		$tpl = (string)file_get_contents(dirname(__DIR__, 3) . '/templates/label-sheet-print.php');
		self::assertStringContainsString("\$_['labelsHtml']", $tpl);
		self::assertStringContainsString("'labelsHtml' => \$html", $controller);
		self::assertStringNotContainsString("'sheetHtml'", $controller);
		self::assertStringContainsString('LabelSheet::MAX_BULK_LABELS', $controller);
	}

	public function testItemBulkLabelsCapsIds(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/ItemController.php');
		self::assertStringContainsString('LabelSheet::MAX_BULK_LABELS', $src);
		self::assertStringContainsString("'labelsHtml' => \$html", $src);
	}
}
