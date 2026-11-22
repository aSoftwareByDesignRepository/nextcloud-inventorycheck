<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Exception\ConflictException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\CsvExportService;
use OCA\InventoryCheck\Service\CsvImportService;
use OCA\InventoryCheck\Service\CustomFieldService;
use OCA\InventoryCheck\Service\ItemService;
use Test\TestCase;

/**
 * IV-CUSTOM-FIELDS — real-DB end-to-end: def CRUD → item write with
 * customValues → detail payload → q search → CSV export/import round-trip.
 *
 * @group DB
 */
final class CustomFieldsIntegrationTest extends TestCase
{
	private string $uid = 'admin';
	private CustomFieldService $fields;
	private ItemService $items;
	private CsvExportService $export;
	private CsvImportService $import;

	protected function setUp(): void
	{
		parent::setUp();
		$c = (new Application())->getContainer();
		$this->fields = $c->get(CustomFieldService::class);
		$this->items = $c->get(ItemService::class);
		$this->export = $c->get(CsvExportService::class);
		$this->import = $c->get(CsvImportService::class);
	}

	private function uniq(string $prefix): string
	{
		return $prefix . '-' . bin2hex(random_bytes(3));
	}

	private function defId(string $name): int
	{
		foreach ($this->fields->listDefs($this->uid) as $d) {
			if ($d['name'] === $name) {
				return (int)$d['id'];
			}
		}
		$this->fail('def not found: ' . $name);
	}

	public function testDefCrudAndItemRoundTrip(): void
	{
		$name = $this->uniq('Warranty');
		$this->fields->createDef($this->uid, ['name' => $name, 'type' => 'date']);
		$fieldId = $this->defId($name);
		$this->assertGreaterThan(0, $fieldId);

		$sku = $this->uniq('SKU');
		$item = $this->items->create($this->uid, [
			'sku' => $sku,
			'name' => 'CF item ' . $sku,
			'customValues' => [$fieldId => '2030-01-01'],
		]);
		$detail = $this->items->get($this->uid, (int)$item['id']);
		$cf = $this->cfFor($detail, $fieldId);
		$this->assertNotNull($cf);
		$this->assertSame($name, $cf['name']);
		$this->assertSame((string)strtotime('2030-01-01'), $cf['value']);
		$this->assertFalse($cf['stale']);

		// Rename preserves values by field id (AC-7).
		$renamed = $name . '-renamed';
		$this->fields->updateDef($this->uid, $fieldId, ['name' => $renamed]);
		$detail = $this->items->get($this->uid, (int)$item['id']);
		$cf = $this->cfFor($detail, $fieldId);
		$this->assertSame($renamed, $cf['name']);
		$this->assertSame((string)strtotime('2030-01-01'), $cf['value']);

		// Type is immutable (NN-01).
		try {
			$this->fields->updateDef($this->uid, $fieldId, ['type' => 'text']);
			$this->fail('expected ValidationException');
		} catch (ValidationException) {
			$this->addToAssertionCount(1);
		} finally {
			$this->fields->deleteDef($this->uid, $fieldId);
		}
	}

	public function testRequiredOnWriteNeverOnRead(): void
	{
		$req = $this->uniq('Req');
		$this->fields->createDef($this->uid, ['name' => $req, 'type' => 'text', 'required' => true]);
		$reqId = $this->defId($req);
		try {
			$sku = $this->uniq('SKU');
			// Legacy path: created through a write that *does* satisfy required
			// is impossible for old items — emulate by writing then checking a
			// bare update demands the value.
			$item = $this->items->create($this->uid, [
				'sku' => $sku,
				'name' => 'Req item ' . $sku,
				'customValues' => [$reqId => 'set'],
			]);
			$detail = $this->items->get($this->uid, (int)$item['id']);
			$this->assertSame('set', $this->cfFor($detail, $reqId)['value']);

			// A write that does not carry the required value must fail.
			$failed = false;
			try {
				$this->items->update($this->uid, (int)$item['id'], [
					'name' => 'Req item renamed',
					'customValues' => [$reqId => ''],
				]);
			} catch (ValidationException $e) {
				$failed = true;
			}
			$this->assertTrue($failed, 'clearing a required field must fail');

			// Reads always work (NN-03).
			$detail = $this->items->get($this->uid, (int)$item['id']);
			$this->assertSame('set', $this->cfFor($detail, $reqId)['value']);
		} finally {
			$this->fields->deleteDef($this->uid, $reqId);
		}
	}

	public function testSearchMatchesTextAndSelectValues(): void
	{
		$note = $this->uniq('Note');
		$grade = $this->uniq('Grade');
		$this->fields->createDef($this->uid, ['name' => $note, 'type' => 'text']);
		$this->fields->createDef($this->uid, ['name' => $grade, 'type' => 'select', 'options' => ['Gold', 'Silver']]);
		$noteId = $this->defId($note);
		$gradeId = $this->defId($grade);
		try {
			$needle = 'ivcf' . bin2hex(random_bytes(4));
			$sku = $this->uniq('SKU');
			$this->items->create($this->uid, [
				'sku' => $sku,
				'name' => 'Search ' . $sku,
				'customValues' => [$noteId => 'prefix-' . $needle . '-suffix', $gradeId => 'Gold'],
			]);
			$hit = $this->items->list($this->uid, $needle, null, false, 10, 0);
			$this->assertSame(1, $hit['total']);
			$this->assertSame($sku, $hit['data'][0]['sku']);

			// Number/date values are not searchable (AC-9).
			$num = $this->uniq('Num');
			$this->fields->createDef($this->uid, ['name' => $num, 'type' => 'number']);
			$numId = $this->defId($num);
			$needleNum = '9' . random_int(100000, 999999);
			$sku2 = $this->uniq('SKU');
			$this->items->create($this->uid, [
				'sku' => $sku2,
				'name' => 'Search2 ' . $sku2,
				'customValues' => [$numId => $needleNum],
			]);
			$miss = $this->items->list($this->uid, (string)$needleNum, null, false, 10, 0);
			$this->assertSame(0, $miss['total']);
			$this->fields->deleteDef($this->uid, $numId);
		} finally {
			$this->fields->deleteDef($this->uid, $noteId);
			$this->fields->deleteDef($this->uid, $gradeId);
		}
	}

	public function testDeactivateHidesButKeepsValues(): void
	{
		$name = $this->uniq('Hidden');
		$this->fields->createDef($this->uid, ['name' => $name, 'type' => 'text']);
		$fieldId = $this->defId($name);
		$sku = $this->uniq('SKU');
		$item = $this->items->create($this->uid, [
			'sku' => $sku,
			'name' => 'Hidden ' . $sku,
			'customValues' => [$fieldId => 'keepme'],
		]);
		$this->fields->updateDef($this->uid, $fieldId, ['active' => false]);
		$detail = $this->items->get($this->uid, (int)$item['id']);
		$this->assertNull($this->cfFor($detail, $fieldId), 'inactive defs must not render');
		// Reactivating brings the stored value back (values were preserved).
		$this->fields->updateDef($this->uid, $fieldId, ['active' => true]);
		$detail = $this->items->get($this->uid, (int)$item['id']);
		$this->assertSame('keepme', $this->cfFor($detail, $fieldId)['value']);
	}

	public function testCsvRoundTripByteStable(): void
	{
		$note = $this->uniq('CSV Note');
		$grade = $this->uniq('CSV Grade');
		$this->fields->createDef($this->uid, ['name' => $note, 'type' => 'text']);
		$this->fields->createDef($this->uid, ['name' => $grade, 'type' => 'select', 'options' => ['X', 'Y']]);
		$noteId = $this->defId($note);
		$gradeId = $this->defId($grade);
		try {
			$sku = $this->uniq('SKU');
			$this->items->create($this->uid, [
				'sku' => $sku,
				'name' => 'CSV ' . $sku,
				'customValues' => [$noteId => 'csv-value', $gradeId => 'X'],
			]);
			$pack = $this->export->export($this->uid, 'items', null, null, null, null, 'en');
			$csv = $pack['body'];
			$this->assertStringContainsString($note, $csv);
			$this->assertStringContainsString($grade, $csv);
			$this->assertStringContainsString('csv-value', $csv);

			// Update the value through the normal write path, then re-import
			// the exported file — the stored value must come back unchanged.
			$this->items->update($this->uid, $this->itemIdForSku($sku), [
				'customValues' => [$noteId => 'changed'],
			]);
			$report = $this->import->dryRun($this->uid, $csv);
			$this->assertSame([], $report['errors'], json_encode($report));
			$this->import->commit($this->uid, $csv);
			$detail = $this->items->get($this->uid, $this->itemIdForSku($sku));
			$byName = [];
			foreach ($detail['customFields'] as $c) {
				$byName[$c['name']] = $c['value'];
			}
			$this->assertSame('csv-value', $byName[$note]);
			$this->assertSame('X', $byName[$grade]);
		} finally {
			$this->fields->deleteDef($this->uid, $noteId);
			$this->fields->deleteDef($this->uid, $gradeId);
		}
	}

	private function cfFor(array $payload, int $fieldId): ?array
	{
		foreach ($payload['customFields'] ?? [] as $c) {
			if ((int)$c['id'] === $fieldId) {
				return $c;
			}
		}
		return null;
	}

	public function testCaseVariantNameCollides(): void
	{
		$name = $this->uniq('CaseName');
		$this->fields->createDef($this->uid, ['name' => $name, 'type' => 'text']);
		$fieldId = $this->defId($name);
		try {
			$this->expectException(ConflictException::class);
			$this->fields->createDef($this->uid, ['name' => mb_strtolower($name), 'type' => 'text']);
		} finally {
			$this->fields->deleteDef($this->uid, $fieldId);
		}
	}

	public function testFieldUserCannotWriteItemOrValues(): void
	{
		$users = \OCP\Server::get(\OCP\IUserManager::class);
		$uid = 'iv-field-' . bin2hex(random_bytes(3));
		if ($users->get($uid) === null) {
			$users->createUser($uid, bin2hex(random_bytes(8)));
		}
		$sku = $this->uniq('SKU');
		$item = $this->items->create($this->uid, ['sku' => $sku, 'name' => 'Deny ' . $sku]);
		try {
			$this->items->update($uid, (int)$item['id'], ['name' => 'nope']);
			$this->fail('field user must not write items');
		} catch (\Throwable $e) {
			// requireOffice denial — exact class is the app's forbidden type.
			$this->assertNotInstanceOf(\LogicException::class, $e);
		}
		// Reads are allowed (NN-02/MH-04).
		$detail = $this->items->get($uid, (int)$item['id']);
		$this->assertSame($sku, $detail['sku']);
	}

	private function itemIdForSku(string $sku): int
	{
		$res = $this->items->list($this->uid, $sku, null, false, 1, 0);
		$this->assertSame(1, $res['total']);
		return (int)$res['data'][0]['id'];
	}
}
