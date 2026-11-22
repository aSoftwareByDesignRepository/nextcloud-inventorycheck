<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\MovementService;
use OCA\InventoryCheck\Service\StockListService;
use OCP\Server;
use Test\TestCase;

/**
 * IV-COMPLIANCE-PACK (AC-7/13): the stock-list artifacts are verified as
 * files — zip structure + content rows — not just an HTTP 200.
 *
 * @group DB
 */
final class StockListIntegrationTest extends TestCase
{
	private string $uid = 'admin';
	private StockListService $stockList;
	private MovementService $movements;
	private LocationService $locations;
	private ItemService $items;
	private string $sku;

	protected function setUp(): void
	{
		parent::setUp();
		$c = (new Application())->getContainer();
		$this->stockList = $c->get(StockListService::class);
		$this->movements = $c->get(MovementService::class);
		$this->locations = $c->get(LocationService::class);
		$this->items = $c->get(ItemService::class);
		$config = Server::get(\OCP\IConfig::class);
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ALLOW_NEGATIVE, '0');

		$suffix = bin2hex(random_bytes(4));
		$this->sku = 'SL-SKU-' . $suffix;
		$loc = $this->locations->create($this->uid, [
			'code' => 'SL-LOC-' . $suffix,
			'name' => 'Stock list loc',
			'kind' => 'warehouse',
		]);
		$item = $this->items->create($this->uid, [
			'sku' => $this->sku,
			'name' => 'Stock list widget',
			'uom' => 'pcs',
		]);
		$this->movements->receive($this->uid, (int)$item['id'], (int)$loc['id'], 7, 'seed');
	}

	public function testOdsIsAValidPackageWithRowsAndFooter(): void
	{
		$body = $this->stockList->buildOds($this->stockList->snapshot());
		$tmp = tempnam(sys_get_temp_dir(), 'sl-ods-');
		file_put_contents($tmp, $body);
		$zip = new \ZipArchive();
		$this->assertTrue($zip->open($tmp) === true, 'ODS must be a readable zip');
		$this->assertSame('application/vnd.oasis.opendocument.spreadsheet', $zip->getFromName('mimetype'));
		$content = $zip->getFromName('content.xml');
		$this->assertIsString($content);
		$this->assertStringContainsString($this->sku, $content, 'item row must appear in the sheet');
		$this->assertStringContainsString('SL-LOC-', $content, 'location column header must appear');
		// Footer: unpriced count + signature line.
		$this->assertStringContainsString('signature', $content);
		$zip->close();
		unlink($tmp);
	}

	public function testPdfIsAValidDocumentWithSignatureLine(): void
	{
		$body = $this->stockList->buildPdf($this->stockList->snapshot());
		$this->assertStringStartsWith('%PDF', $body);
		$this->assertStringContainsString('InventoryCheck', $body);
		// Narrow columns clip text to width — assert the wide item-name
		// column and the SKU prefix rather than the full clipped cell.
		$this->assertStringContainsString('Stock list widget', $body, 'item row must appear in the PDF stream');
		$this->assertStringContainsString('SL-SKU', $body);
		$this->assertStringContainsString('signature', $body, 'signature line must be present');
		$this->assertStringContainsString('%%EOF', $body);
	}

	public function testSnapshotOnlyCountsStockOnHand(): void
	{
		$snap = $this->stockList->snapshot();
		$skus = array_map(static fn (array $r) => $r[0], $snap['rows']);
		$this->assertContains($this->sku, $skus);
		// An item with zero balance everywhere must not appear.
		$this->items->create($this->uid, [
			'sku' => 'SL-ZERO-' . bin2hex(random_bytes(4)),
			'name' => 'Never stocked',
		]);
		$snap2 = $this->stockList->snapshot();
		$zeroRows = array_filter($snap2['rows'], static fn (array $r) => str_starts_with((string)$r[0], 'SL-ZERO-'));
		$this->assertSame([], array_values($zeroRows));
	}
}
