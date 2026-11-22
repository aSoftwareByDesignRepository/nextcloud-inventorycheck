<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Db\ItemField;
use OCA\InventoryCheck\Db\ItemFieldMapper;
use OCA\InventoryCheck\Db\ItemFieldValueMapper;
use OCA\InventoryCheck\Exception\ConflictException;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\Clock;
use OCA\InventoryCheck\Service\CustomFieldService;
use PHPUnit\Framework\TestCase;

/**
 * IV-CUSTOM-FIELDS: def CRUD guards (unique name, immutable type, select
 * options), value coercion per type, required-on-write, search/csv plumbing.
 */
final class CustomFieldServiceTest extends TestCase
{
	private function def(int $id, string $name, string $type, array $opts = [], bool $required = false, bool $active = true): ItemField
	{
		$f = new ItemField();
		$f->setId($id);
		$f->setName($name);
		$f->setType($type);
		$f->setOptionsJson($opts === [] ? null : json_encode($opts));
		$f->setRequired($required);
		$f->setActive($active);
		return $f;
	}

	private function service(array $defs = [], array $values = []): CustomFieldService
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('isAppAdmin')->willReturn(true);
		$fields = $this->createMock(ItemFieldMapper::class);
		$fields->method('all')->willReturn($defs);
		$fields->method('allActive')->willReturn(array_values(array_filter($defs, static fn (ItemField $f) => $f->getActive())));
		$fields->method('byIds')->willReturnCallback(static function (array $ids) use ($defs): array {
			$out = [];
			foreach ($defs as $f) {
				if (in_array((int)$f->getId(), $ids, true)) {
					$out[(int)$f->getId()] = $f;
				}
			}
			return $out;
		});
		$valueMap = $this->createMock(ItemFieldValueMapper::class);
		$valueMap->method('valuesForItem')->willReturn($values);
		$valueMap->method('valuesForItems')->willReturnCallback(static function (array $ids) use ($values): array {
			$out = [];
			foreach ($ids as $id) {
				$out[$id] = $values;
			}
			return $out;
		});
		$clock = $this->createMock(Clock::class);
		$clock->method('now')->willReturn(1_800_000_000);
		return new CustomFieldService($access, $fields, $valueMap, $clock);
	}

	/* ── Value coercion ────────────────────────────────────────────── */

	public function testValidateValuesCoercesEachType(): void
	{
		$svc = $this->service([
			$this->def(1, 'Note', 'text'),
			$this->def(2, 'Weight', 'number'),
			$this->def(3, 'Warranty', 'date'),
			$this->def(4, 'Grade', 'select', ['A', 'B']),
		]);
		$out = $svc->validateValues([
			1 => '  hello  ',
			2 => '12.50',
			3 => '2027-01-01',
			4 => 'B',
		]);
		$this->assertSame('hello', $out[1]);
		$this->assertSame('12.5', $out[2]);
		$this->assertSame((string)strtotime('2027-01-01'), $out[3]);
		$this->assertSame('B', $out[4]);
	}

	public function testEmptyValueNormalizesToNull(): void
	{
		$svc = $this->service([$this->def(1, 'Note', 'text')]);
		$this->assertSame([1 => null], $svc->validateValues([1 => '']));
		$this->assertSame([1 => null], $svc->validateValues([1 => null]));
	}

	public function testTextOverLimitFails(): void
	{
		$svc = $this->service([$this->def(1, 'Note', 'text')]);
		$this->expectException(ValidationException::class);
		$svc->validateValues([1 => str_repeat('x', ItemField::MAX_VALUE_CHARS + 1)]);
	}

	public function testNumberRejectsNonNumeric(): void
	{
		$svc = $this->service([$this->def(1, 'W', 'number')]);
		$this->expectException(ValidationException::class);
		$svc->validateValues([1 => 'abc']);
	}

	public function testDateRejectsGarbage(): void
	{
		$svc = $this->service([$this->def(1, 'D', 'date')]);
		$this->expectException(ValidationException::class);
		$svc->validateValues([1 => 'not-a-date']);
	}

	public function testSelectRejectsStaleOption(): void
	{
		$svc = $this->service([$this->def(1, 'G', 'select', ['A'])]);
		$this->expectException(ValidationException::class);
		$svc->validateValues([1 => 'Z']);
	}

	public function testUnknownAndInactiveDefsIgnored(): void
	{
		$svc = $this->service([$this->def(1, 'Off', 'text', [], false, false)]);
		$this->assertSame([], $svc->validateValues([1 => 'x', 99 => 'y']));
	}

	public function testFieldErrorKeysAreCfIds(): void
	{
		$svc = $this->service([$this->def(7, 'W', 'number')]);
		try {
			$svc->validateValues([7 => 'nope']);
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('cf_7', $e->getDetails()[0]['field']);
		}
	}

	/* ── Required on write (NN-03) ─────────────────────────────────── */

	public function testRequiredMissingFails(): void
	{
		$svc = $this->service([$this->def(1, 'R', 'text', [], true)]);
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('required');
		$svc->assertRequiredSatisfied([], []);
	}

	public function testRequiredSatisfiedByExistingValue(): void
	{
		$svc = $this->service([$this->def(1, 'R', 'text', [], true)]);
		$svc->assertRequiredSatisfied([], [1 => 'kept']);
		$this->addToAssertionCount(1);
	}

	public function testRequiredNotSatisfiedByNullNormalized(): void
	{
		$svc = $this->service([$this->def(1, 'R', 'text', [], true)]);
		$this->expectException(ValidationException::class);
		$svc->assertRequiredSatisfied([1 => null], [1 => 'had']);
	}

	public function testInactiveRequiredDefDoesNotBlock(): void
	{
		$svc = $this->service([$this->def(1, 'R', 'text', [], true, false)]);
		$svc->assertRequiredSatisfied([], []);
		$this->addToAssertionCount(1);
	}

	/* ── Payload merge + stale flag ────────────────────────────────── */

	public function testPayloadFlagsStaleSelectValue(): void
	{
		$svc = $this->service([$this->def(1, 'G', 'select', ['A'])], [1 => 'Z']);
		$payload = $svc->payloadForItem(42);
		$this->assertSame('Z', $payload[0]['value']);
		$this->assertTrue($payload[0]['stale']);
	}

	public function testPayloadEmptyWhenNoActiveDefs(): void
	{
		$svc = $this->service([$this->def(1, 'Off', 'text', [], false, false)]);
		$this->assertSame([], $svc->payloadForItem(42));
	}

	/* ── Def guards ────────────────────────────────────────────────── */

	public function testCreateRejectsUnknownType(): void
	{
		$svc = $this->service();
		$this->expectException(ValidationException::class);
		$svc->createDef('admin', ['name' => 'X', 'type' => 'regex']);
	}

	public function testSelectRequiresOptions(): void
	{
		$svc = $this->service();
		try {
			$svc->createDef('admin', ['name' => 'G', 'type' => 'select', 'options' => []]);
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('options', $e->getDetails()[0]['field']);
		}
	}

	public function testUpdateRejectsTypeChange(): void
	{
		$existing = $this->def(5, 'G', 'text');
		$fields = $this->createMock(ItemFieldMapper::class);
		$fields->method('findById')->willReturn($existing);
		$access = $this->createMock(AccessControlService::class);
		$access->method('isAppAdmin')->willReturn(true);
		$values = $this->createMock(ItemFieldValueMapper::class);
		$clock = $this->createMock(Clock::class);
		$svc = new CustomFieldService($access, $fields, $values, $clock);
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('field_type_immutable');
		$svc->updateDef('admin', 5, ['type' => 'number']);
	}

	public function testCreateDefSelectTrimsAndDedupesOptions(): void
	{
		$fields = $this->createMock(ItemFieldMapper::class);
		$fields->method('activeNameExists')->willReturn(false);
		$fields->method('nextPosition')->willReturn(10);
		$fields->expects($this->once())->method('insert')->willReturnCallback(
			static function (ItemField $f): ItemField {
				$f->setId(9);
				return $f;
			});
		$access = $this->createMock(AccessControlService::class);
		$access->method('isAppAdmin')->willReturn(true);
		$svc = new CustomFieldService($access, $fields, $this->createMock(ItemFieldValueMapper::class), $this->createMock(Clock::class));
		$api = $svc->createDef('admin', ['name' => 'G', 'type' => 'select', 'options' => [' A ', 'B', 'A', '', 'B']]);
		$this->assertSame(['A', 'B'], $api['options']);
		$this->assertSame(10, $api['position']);
	}

	public function testTextBoundaryExactlyMaxCharsPasses(): void
	{
		$svc = $this->service([$this->def(1, 'Note', 'text')]);
		$out = $svc->validateValues([1 => str_repeat('x', ItemField::MAX_VALUE_CHARS)]);
		$this->assertSame(str_repeat('x', ItemField::MAX_VALUE_CHARS), $out[1]);
	}

	public function testCreateEnforcesSoftCapOnActiveDefs(): void
	{
		$defs = [];
		for ($i = 1; $i <= CustomFieldService::MAX_DEFS_SOFT_CAP; $i++) {
			$defs[] = $this->def($i, 'F' . $i, 'text');
		}
		// Inactive defs do not count toward the cap.
		$defs[] = $this->def(999, 'Off', 'text', [], false, false);
		$svc = $this->service($defs);
		$this->expectException(ConflictException::class);
		$this->expectExceptionMessage('field_cap_reached');
		$svc->createDef('admin', ['name' => 'Over', 'type' => 'text']);
	}

	public function testCreateRejectsDuplicateActiveName(): void
	{
		$fields = $this->createMock(ItemFieldMapper::class);
		$fields->method('activeNameExists')->willReturn(true);
		$access = $this->createMock(AccessControlService::class);
		$access->method('isAppAdmin')->willReturn(true);
		$svc = new CustomFieldService($access, $fields, $this->createMock(ItemFieldValueMapper::class), $this->createMock(Clock::class));
		$this->expectException(ConflictException::class);
		$this->expectExceptionMessage('field_name_exists');
		$svc->createDef('admin', ['name' => 'Dup', 'type' => 'text']);
	}

	/* ── valueCount (uniform 404) ──────────────────────────────────── */

	public function testValueCountUnknownFieldThrowsNotFound(): void
	{
		$fields = $this->createMock(ItemFieldMapper::class);
		$fields->method('findById')->willThrowException(new NotFoundException('unknown_field'));
		$values = $this->createMock(ItemFieldValueMapper::class);
		$values->expects($this->never())->method('countForField');
		$access = $this->createMock(AccessControlService::class);
		$svc = new CustomFieldService($access, $fields, $values, $this->createMock(Clock::class));
		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('unknown_field');
		$svc->valueCount(99999);
	}

	public function testValueCountResolvesDefThenCounts(): void
	{
		$fields = $this->createMock(ItemFieldMapper::class);
		$fields->expects($this->once())->method('findById')->with(7)->willReturn($this->def(7, 'N', 'text'));
		$values = $this->createMock(ItemFieldValueMapper::class);
		$values->method('countForField')->with(7)->willReturn(3);
		$access = $this->createMock(AccessControlService::class);
		$svc = new CustomFieldService($access, $fields, $values, $this->createMock(Clock::class));
		$this->assertSame(3, $svc->valueCount(7));
	}

	/* ── Search + CSV plumbing ─────────────────────────────────────── */

	public function testSearchableFieldIdsTextAndSelectOnly(): void
	{
		$svc = $this->service([
			$this->def(1, 'T', 'text'),
			$this->def(2, 'N', 'number'),
			$this->def(3, 'D', 'date'),
			$this->def(4, 'S', 'select', ['A']),
			$this->def(5, 'Off', 'text', [], false, false),
		]);
		$this->assertSame([1, 4], $svc->searchableFieldIds());
	}

	public function testColumnMapCanonicalizesNames(): void
	{
		$svc = $this->service([
			$this->def(1, 'Warranty End', 'date'),
			$this->def(2, 'serial-class', 'text'),
		]);
		$map = $svc->columnMap();
		$this->assertSame(1, (int)$map['warranty_end']->getId());
		$this->assertSame(2, (int)$map['serial_class']->getId());
	}
}
