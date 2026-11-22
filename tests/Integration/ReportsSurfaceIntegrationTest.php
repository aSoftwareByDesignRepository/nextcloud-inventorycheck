<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Db\Item;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Exception\PermissionDeniedException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\CsvExportService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\MovementService;
use OCA\InventoryCheck\Service\ReportService;
use OCP\IConfig;
use OCP\Server;
use Test\TestCase;

/**
 * IV-REPORTS-SURFACE — real-DB coverage: dead-stock clock semantics,
 * per-location value, CSV kinds, office gate, days validation.
 *
 * @group DB
 */
final class ReportsSurfaceIntegrationTest extends TestCase
{
	private string $uid = 'admin';
	private ReportService $reports;
	private MovementService $movements;
	private LocationService $locations;
	private ItemMapper $itemMapper;
	private CsvExportService $csv;

	protected function setUp(): void
	{
		parent::setUp();
		$c = (new Application())->getContainer();
		$this->reports = $c->get(ReportService::class);
		$this->movements = $c->get(MovementService::class);
		$this->locations = $c->get(LocationService::class);
		$this->itemMapper = $c->get(ItemMapper::class);
		$this->csv = $c->get(CsvExportService::class);
	}

	private function oldItem(string $code, ?int $priceMinor): int
	{
		$item = new Item();
		$item->setSku($code);
		$item->setScanCode($code);
		$item->setName('report item ' . $code);
		$item->setUom('pcs');
		$item->setActive(true);
		$item->setLastPriceMinor($priceMinor);
		$item->setCreatedAt(1);
		$item->setUpdatedAt(1);
		$item->setCreatedBy($this->uid);
		return (int)$this->itemMapper->insert($item)->getId();
	}

	public function testDeadStockRealDb(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$loc = $this->locations->create($this->uid, [
			'code' => 'RS-L-' . $suffix, 'name' => 'Report Loc', 'kind' => 'warehouse',
		]);
		$deadId = $this->oldItem('RS-DEAD-' . $suffix, 250);
		$movedId = $this->oldItem('RS-MOVED-' . $suffix, 250);
		$this->movements->receive($this->uid, $deadId, (int)$loc['id'], 4, 'stock');
		$this->movements->receive($this->uid, $movedId, (int)$loc['id'], 4, 'stock');
		// Backdate ONLY the dead item's movement so it is untouched >90d;
		// the other movement stays "now" and resets its clock.
		$db = Server::get(\OCP\IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->update('iv_movements')
			->set('created_at', $qb->createNamedParameter(1, \PDO::PARAM_INT))
			->where($qb->expr()->eq('item_id', $qb->createNamedParameter($deadId, \PDO::PARAM_INT)))
			->executeStatement();

		$rows = $this->reports->deadStock(90, 1)['rows'];
		$ids = array_column($rows, 'itemId');
		$this->assertContains($deadId, $ids, 'backdated-movement item must appear');
		$this->assertNotContains($movedId, $ids, 'recent movement resets the clock');
		$row = $rows[array_search($deadId, $ids, true)];
		$this->assertSame(4, $row['totalQty']);
		$this->assertSame(1000, $row['valueMinor']); // 4 × 250
	}

	public function testDeadStockNeverMovedOldItem(): void
	{
		$id = $this->oldItem('RS-NOMOVE-' . bin2hex(random_bytes(3)), null);
		// Stock without a movement is impossible — give it stock via a
		// backdated receive (still counts as "moved", just long ago).
		$loc = $this->locations->create($this->uid, [
			'code' => 'RS-NM-' . bin2hex(random_bytes(3)), 'name' => 'x', 'kind' => 'site',
		]);
		$this->movements->receive($this->uid, $id, (int)$loc['id'], 2, 'stock');
		$db = Server::get(\OCP\IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->update('iv_movements')
			->set('created_at', $qb->createNamedParameter(1, \PDO::PARAM_INT))
			->where($qb->expr()->eq('item_id', $qb->createNamedParameter($id, \PDO::PARAM_INT)))
			->executeStatement();
		$report = $this->reports->deadStock(90, 1);
		$ids = array_column($report['rows'], 'itemId');
		$this->assertContains($id, $ids);
		$row = $report['rows'][array_search($id, $ids, true)];
		$this->assertNull($row['valueMinor']);
		$this->assertGreaterThanOrEqual(1, $report['unpricedCount']);
	}

	public function testLocationValueRealDb(): void
	{
		$suffix = bin2hex(random_bytes(3));
		$locA = $this->locations->create($this->uid, [
			'code' => 'RS-A-' . $suffix, 'name' => 'A', 'kind' => 'warehouse',
		]);
		$locB = $this->locations->create($this->uid, [
			'code' => 'RS-B-' . $suffix, 'name' => 'B', 'kind' => 'van',
		]);
		$priced = $this->oldItem('RS-P-' . $suffix, 100);
		$unpriced = $this->oldItem('RS-U-' . $suffix, null);
		$this->movements->receive($this->uid, $priced, (int)$locA['id'], 3, 'stock');
		$this->movements->receive($this->uid, $priced, (int)$locB['id'], 5, 'stock');
		$this->movements->receive($this->uid, $unpriced, (int)$locA['id'], 9, 'stock');

		$report = $this->reports->locationValue(1);
		$byLoc = [];
		foreach ($report['rows'] as $row) {
			$byLoc[(int)$row['locationId']] = $row;
		}
		$this->assertSame(300, $byLoc[(int)$locA['id']]['valueMinor']);
		$this->assertSame(2, $byLoc[(int)$locA['id']]['itemCount']);
		$this->assertSame(500, $byLoc[(int)$locB['id']]['valueMinor']);
		$this->assertTrue($report['isEstimate']);
		$this->assertGreaterThanOrEqual(1, $report['unpricedCount']);
	}

	public function testCsvReportKinds(): void
	{
		foreach (['valuation', 'location_value', 'dead_stock'] as $kind) {
			$out = $this->csv->export($this->uid, $kind, null, null, null, null, 'en', null, 30);
			$this->assertStringStartsWith('inventorycheck-', $out['filename']);
			$this->assertStringEndsWith('.csv', $out['filename']);
			$this->assertStringContainsString('text/csv', $out['contentType']);
			$this->assertStringStartsWith("\xEF\xBB\xBF", $out['body'], 'BOM for Excel safety');
		}
		$de = $this->csv->export($this->uid, 'dead_stock', null, null, null, null, 'de', null, 30);
		$this->assertStringContainsString('Artikel', $de['body'], 'localized DE header');
	}

	public function testCsvDeadStockRejectsBadDays(): void
	{
		$this->expectException(ValidationException::class);
		$this->csv->export($this->uid, 'dead_stock', null, null, null, null, 'en', null, 0);
	}

	public function testReportExportKindsAreOfficeOnly(): void
	{
		$this->expectException(PermissionDeniedException::class);
		// Non-office actor: requireOffice throws before any report data.
		$this->csv->export('iv-field-outsider-' . bin2hex(random_bytes(3)), 'valuation', null, null, null, null);
	}
}
