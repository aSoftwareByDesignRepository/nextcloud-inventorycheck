<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Service\CodeRules;
use PHPUnit\Framework\TestCase;

final class CodeRulesTest extends TestCase
{
	/** @dataProvider skuProvider */
	public function testSku(string $value, bool $ok): void
	{
		$this->assertSame($ok, CodeRules::isValidSku($value));
	}

	public function skuProvider(): array
	{
		return [
			'plain' => ['FILTER-42', true],
			'slash' => ['A/B_1.2', true],
			'space' => ['BAD CODE', false],
			'umlaut' => ['FÄLTER', false],
			'empty' => ['', false],
			'len64' => [str_repeat('A', 64), true],
			'len65' => [str_repeat('A', 65), false],
		];
	}

	public function testScanLength(): void
	{
		$this->assertTrue(CodeRules::isValidScanCode(str_repeat('B', 128)));
		$this->assertFalse(CodeRules::isValidScanCode(str_repeat('B', 129)));
	}

	public function testLocationKind(): void
	{
		$this->assertTrue(CodeRules::isValidLocationKind('van'));
		$this->assertTrue(CodeRules::isValidLocationKind('other'));
		$this->assertFalse(CodeRules::isValidLocationKind('garage'));
	}

	public function testCrossFieldCollision(): void
	{
		$others = [
			['id' => 1, 'sku' => 'AAA', 'scanCode' => 'SCAN-A'],
			['id' => 2, 'sku' => 'BBB', 'scanCode' => 'SCAN-B'],
		];
		$this->assertTrue(CodeRules::conflictsWithOthers(null, 'AAA', 'NEW', $others));
		$this->assertTrue(CodeRules::conflictsWithOthers(null, 'NEW', 'SCAN-B', $others));
		$this->assertTrue(CodeRules::conflictsWithOthers(null, 'SCAN-A', 'X', $others));
		$this->assertFalse(CodeRules::conflictsWithOthers(1, 'AAA', 'SCAN-A', $others));
		$this->assertFalse(CodeRules::conflictsWithOthers(null, 'CCC', 'SCAN-C', $others));
	}

	public function testSameItemSkuEqualsScanAllowed(): void
	{
		$others = [['id' => 1, 'sku' => 'X', 'scanCode' => 'X']];
		$this->assertFalse(CodeRules::conflictsWithOthers(1, 'X', 'X', $others));
	}

	public function testItemCodesCollideWithLocationCodes(): void
	{
		$locs = ['VAN-1', 'SHELF-A'];
		$this->assertTrue(CodeRules::conflictsWithLocationCodes('VAN-1', 'NEW', $locs));
		$this->assertTrue(CodeRules::conflictsWithLocationCodes('NEW', 'SHELF-A', $locs));
		$this->assertFalse(CodeRules::conflictsWithLocationCodes('ITEM-1', 'SCAN-1', $locs));
	}

	public function testLocationCodeCollidesWithItemSkuOrScan(): void
	{
		$items = [
			['id' => 1, 'sku' => 'FILTER-42', 'scanCode' => 'SCAN-42'],
			['id' => 2, 'sku' => 'SEAL-9', 'scanCode' => 'SEAL-9'],
		];
		$this->assertTrue(CodeRules::locationConflictsWithItems('FILTER-42', $items));
		$this->assertTrue(CodeRules::locationConflictsWithItems('SCAN-42', $items));
		$this->assertTrue(CodeRules::locationConflictsWithItems('SEAL-9', $items));
		$this->assertFalse(CodeRules::locationConflictsWithItems('BIN-1', $items));
	}

	public function testCodesLockConstantStable(): void
	{
		$this->assertSame('inventorycheck/item_codes', CodeRules::CODES_LOCK);
	}

	public function testTrim(): void
	{
		$this->assertSame('AB', CodeRules::trim("  AB\t"));
	}
}
