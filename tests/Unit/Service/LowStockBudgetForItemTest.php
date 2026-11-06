<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Db\Item;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\MovementMapper;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\LocationAclService;
use OCA\InventoryCheck\Service\LowStockService;
use OCA\InventoryCheck\Service\RestockConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class LowStockBudgetForItemTest extends TestCase
{
	private function restock(array $appValues): RestockConfig
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, $default = '') => $appValues[$key] ?? $default
		);
		return new RestockConfig($config);
	}

	private function item(?string $category): Item
	{
		$item = new Item();
		$item->setCategory($category);
		return $item;
	}

	/**
	 * @param array{item?: Item|null, itemThrows?: bool, isOffice?: bool, mtd?: array<string, int>, restock?: ?RestockConfig, noAccess?: bool, noRestock?: bool, noMovements?: bool} $opts
	 */
	private function service(array $opts): LowStockService
	{
		$items = $this->createMock(ItemMapper::class);
		if (($opts['itemThrows'] ?? false) === true) {
			$items->method('findById')->willThrowException(new NotFoundException('unknown_item'));
		} else {
			$items->method('findById')->willReturn($opts['item'] ?? $this->item('Supplies'));
		}
		$access = null;
		if (!($opts['noAccess'] ?? false)) {
			$access = $this->createMock(AccessControlService::class);
			$access->method('isOffice')->willReturn($opts['isOffice'] ?? true);
		}
		$restock = ($opts['noRestock'] ?? false) ? null : ($opts['restock'] ?? $this->restock([]));
		$movements = null;
		if (!($opts['noMovements'] ?? false)) {
			$movements = $this->createMock(MovementMapper::class);
			$movements->method('receivedSpendByCategorySince')->willReturn($opts['mtd'] ?? []);
		}
		return new LowStockService(
			$items,
			$this->createMock(BalanceMapper::class),
			$this->createMock(IConfig::class),
			$this->createMock(LocationAclService::class),
			$access,
			$restock,
			$movements,
			null,
		);
	}

	public function testOfficeActorGetsSnapshot(): void
	{
		$svc = $this->service([
			'item' => $this->item('Supplies'),
			'restock' => $this->restock([
				RestockConfig::KEY_BUDGETS => '{"Supplies":100000}',
				RestockConfig::KEY_EMERGENCY_BUDGET => '5000',
			]),
			'mtd' => ['Supplies' => 30000],
		]);
		self::assertSame(
			[
				'category' => 'Supplies',
				'budgetMinor' => 100000,
				'spentMtdMinor' => 30000,
				'remainingMinor' => 70000,
				'emergencyMinor' => 5000,
			],
			$svc->budgetForItem('alice', 1),
		);
	}

	public function testNonOfficeNeverSeesMoney(): void
	{
		$items = $this->createMock(ItemMapper::class);
		$items->expects(self::never())->method('findById');
		$access = $this->createMock(AccessControlService::class);
		$access->method('isOffice')->willReturn(false);
		$svc = new LowStockService(
			$items,
			$this->createMock(BalanceMapper::class),
			$this->createMock(IConfig::class),
			$this->createMock(LocationAclService::class),
			$access,
			$this->restock([RestockConfig::KEY_BUDGETS => '{"Supplies":100000}']),
			$this->createMock(MovementMapper::class),
			null,
		);
		self::assertNull($svc->budgetForItem('bob', 1));
	}

	public function testMissingOptionalDepsReturnNull(): void
	{
		$items = $this->createMock(ItemMapper::class);
		$items->method('findById')->willReturn($this->item('Supplies'));
		$restock = $this->restock([RestockConfig::KEY_BUDGETS => '{"Supplies":100000}']);
		$movements = $this->createMock(MovementMapper::class);

		foreach ([null, $this->createMock(AccessControlService::class)] as $access) {
			foreach ([null, $restock] as $r) {
				foreach ([null, $movements] as $m) {
					if ($access !== null && $r !== null && $m !== null) {
						continue;
					}
					$svc = new LowStockService(
						$items,
						$this->createMock(BalanceMapper::class),
						$this->createMock(IConfig::class),
						$this->createMock(LocationAclService::class),
						$access,
						$r,
						$m,
						null,
					);
					self::assertNull($svc->budgetForItem('alice', 1));
				}
			}
		}
	}

	public function testUnknownItemIsSilentNull(): void
	{
		$svc = $this->service([
			'itemThrows' => true,
			'restock' => $this->restock([RestockConfig::KEY_BUDGETS => '{"Supplies":100000}']),
		]);
		self::assertNull($svc->budgetForItem('alice', 999));
	}

	public function testCategoryWithoutBudgetReturnsNull(): void
	{
		$svc = $this->service([
			'item' => $this->item('Office'),
			'restock' => $this->restock([RestockConfig::KEY_BUDGETS => '{"Supplies":100000}']),
		]);
		self::assertNull($svc->budgetForItem('alice', 1));
	}

	public function testZeroBudgetMeansUncappedNotZero(): void
	{
		$svc = $this->service([
			'item' => $this->item('Supplies'),
			'restock' => $this->restock([RestockConfig::KEY_BUDGETS => '{"Supplies":0}']),
		]);
		self::assertNull($svc->budgetForItem('alice', 1));
	}

	public function testUncategorizedMergesNullCategorySpend(): void
	{
		$svc = $this->service([
			'item' => $this->item(null),
			'restock' => $this->restock([RestockConfig::KEY_BUDGETS => '{"Uncategorized":50000}']),
			'mtd' => ['' => 2000, 'Uncategorized' => 1000],
		]);
		$out = $svc->budgetForItem('alice', 1);
		self::assertNotNull($out);
		self::assertSame('Uncategorized', $out['category']);
		self::assertSame(3000, $out['spentMtdMinor']);
		self::assertSame(47000, $out['remainingMinor']);
	}

	public function testOverspendClampsRemainingAtZero(): void
	{
		$svc = $this->service([
			'item' => $this->item('Supplies'),
			'restock' => $this->restock([RestockConfig::KEY_BUDGETS => '{"Supplies":1000}']),
			'mtd' => ['Supplies' => 5000],
		]);
		$out = $svc->budgetForItem('alice', 1);
		self::assertNotNull($out);
		self::assertSame(0, $out['remainingMinor']);
	}

	public function testNoEmergencyConfiguredIsNull(): void
	{
		$svc = $this->service([
			'item' => $this->item('Supplies'),
			'restock' => $this->restock([RestockConfig::KEY_BUDGETS => '{"Supplies":100000}']),
			'mtd' => [],
		]);
		$out = $svc->budgetForItem('alice', 1);
		self::assertNotNull($out);
		self::assertNull($out['emergencyMinor']);
	}
}
