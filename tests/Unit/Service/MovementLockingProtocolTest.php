<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Deadlock / race protocol encoded as executable source assertions
 * (SPEC S2/S5/S6, AC-8/AC-9) plus behavioural checks of the provider-aware
 * row-lock suffix.
 */
final class MovementLockingProtocolTest extends TestCase
{
	public function testBalanceMapperLocksPairsAscendingWithForUpdate(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/lib/Db/BalanceMapper.php');
		self::assertNotFalse($src);
		self::assertStringContainsString('FOR UPDATE', $src);
		self::assertStringContainsString('usort', $src);
		self::assertMatchesRegularExpression(
			"/itemId'\]\s*<=>\s*\\\$b\['itemId'\]/",
			$src,
		);
		self::assertMatchesRegularExpression(
			"/locationId'\]\s*<=>\s*\\\$b\['locationId'\]/",
			$src,
		);
		self::assertStringContainsString('PLATFORM_SQLITE', $src);
	}

	public function testScanTransferRequiresToLocationId(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MovementService.php');
		self::assertNotFalse($src);
		self::assertStringContainsString("\$toLocationId === null || \$toLocationId <= 0", $src);
		self::assertStringContainsString("['field' => 'toLocationId'", $src);
	}

	public function testMovementServiceDocumentsAscendingLockOrderAndUsesLockPairs(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MovementService.php');
		self::assertNotFalse($src);
		self::assertStringContainsString('ascending (item_id, location_id)', $src);
		self::assertStringContainsString('lockPairs', $src);
		self::assertStringContainsString('beginTransaction', $src);
		self::assertStringContainsString('InsufficientStockException', $src);
		self::assertGreaterThanOrEqual(
			2,
			substr_count($src, 'lockPairs'),
			'receive/issue and transfer paths must both lock',
		);
	}

	public function testEnsureZeroRowUsesInsertIgnoreConflict(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/lib/Db/BalanceMapper.php');
		self::assertNotFalse($src);
		self::assertStringContainsString(
			'insertIgnoreConflict',
			$src,
			'a raw unique violation would abort the surrounding transaction on PostgreSQL',
		);
		self::assertStringNotContainsString(
			'catch (\\Throwable)',
			$src,
			'swallowing insert errors hides PG transaction aborts',
		);
	}

	public function testMovementsTakeEntitySharedLocksInsideTheTransaction(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MovementService.php');
		self::assertNotFalse($src);
		// Active checks must be locking reads inside the open transaction,
		// not unlocked pre-checks (S5/S6 TOCTOU).
		self::assertSame(3, substr_count($src, '$this->lockActiveItem('), 'receive/issue, transfer, adjust');
		self::assertSame(3, substr_count($src, '$this->lockActiveLocations('), 'receive/issue, transfer, adjust');
		self::assertStringContainsString('lockById($itemId, false)', $src);
		self::assertStringContainsString('lockById($id, false)', $src);
		self::assertStringContainsString('sort($ids)', $src);
		self::assertStringNotContainsString('requireActiveItem', $src);
	}

	public function testDeactivateAndDeletePathsTakeExclusiveEntityLocks(): void
	{
		$items = file_get_contents(dirname(__DIR__, 3) . '/lib/Service/ItemService.php');
		$locations = file_get_contents(dirname(__DIR__, 3) . '/lib/Service/LocationService.php');
		self::assertNotFalse($items);
		self::assertNotFalse($locations);
		self::assertSame(2, substr_count($items, 'lockById($id, true)'), 'item update + delete');
		self::assertSame(2, substr_count($locations, 'lockById($id, true)'), 'location update + delete');
		self::assertStringContainsString('withCodesLock', $items);
		self::assertStringContainsString('UniqueViolation::is', $items);
		self::assertStringContainsString('UniqueViolation::is', $locations);
	}

	public function testRowLockSuffixPerProvider(): void
	{
		self::assertSame(' LOCK IN SHARE MODE', $this->suffixFor(IDBConnection::PLATFORM_MYSQL, false));
		self::assertSame(' FOR SHARE', $this->suffixFor(IDBConnection::PLATFORM_POSTGRES, false));
		self::assertSame('', $this->suffixFor(IDBConnection::PLATFORM_SQLITE, false));
		self::assertSame('', $this->suffixFor(IDBConnection::PLATFORM_SQLITE, true));
		self::assertSame(' FOR UPDATE', $this->suffixFor(IDBConnection::PLATFORM_MYSQL, true));
		self::assertSame(' FOR UPDATE', $this->suffixFor(IDBConnection::PLATFORM_POSTGRES, true));
		// Unknown providers fall back to the universal exclusive lock.
		self::assertSame(' FOR UPDATE', $this->suffixFor(IDBConnection::PLATFORM_ORACLE, false));
	}

	private function suffixFor(string $provider, bool $exclusive): string
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabaseProvider')->willReturn($provider);

		$probe = new class($db) {
			use \OCA\InventoryCheck\Db\RowLocking;

			public function __construct(private readonly IDBConnection $db)
			{
			}

			public function suffix(bool $exclusive): string
			{
				return $this->rowLockSuffix($exclusive);
			}
		};

		return $probe->suffix($exclusive);
	}
}
