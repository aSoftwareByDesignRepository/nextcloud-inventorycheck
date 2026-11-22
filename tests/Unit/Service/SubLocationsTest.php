<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Db\CycleCampaignMapper;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\Location;
use OCA\InventoryCheck\Db\LocationFavouriteMapper;
use OCA\InventoryCheck\Db\LocationMapper;
use OCA\InventoryCheck\Exception\ConflictException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\Clock;
use OCA\InventoryCheck\Service\LocationAclService;
use OCA\InventoryCheck\Service\LocationService;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

/**
 * IV-SUB-LOCATIONS: depth cap 1 both directions (NN-01), parent picker
 * rules, pathLabel decoration (MH-03), delete-refuse (NN-04).
 */
final class SubLocationsTest extends TestCase
{
	private LocationMapper $locations;
	private LocationService $service;

	protected function setUp(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$this->locations = $this->createMock(LocationMapper::class);
		$access = $this->createMock(AccessControlService::class);
		$clock = $this->createMock(Clock::class);
		$clock->method('now')->willReturn(1_800_000_000);
		$acl = $this->createMock(LocationAclService::class);
		$acl->method('visibleLocationIds')->willReturn(null);
		$acl->method('canAccessLocation')->willReturn(true);
		$locking = $this->createMock(ILockingProvider::class);
		$this->service = new LocationService(
			$db,
			$this->locations,
			$access,
			$clock,
			$acl,
			$this->createMock(CycleCampaignMapper::class),
			$this->createMock(ItemMapper::class),
			$locking,
			$this->createMock(LocationFavouriteMapper::class),
		);
	}

	private function loc(int $id, string $code, ?int $parentId = null, bool $active = true): Location
	{
		$loc = new Location();
		$loc->setId($id);
		$loc->setCode($code);
		$loc->setName('Loc ' . $code);
		$loc->setKind('warehouse');
		$loc->setActive($active);
		$loc->setParentId($parentId);
		return $loc;
	}

	public function testCreateChildUnderRootWorks(): void
	{
		$parent = $this->loc(10, 'WH');
		$this->locations->method('findById')->with(10)->willReturn($parent);
		$this->locations->method('findByCode')->willReturn(null);
		$this->locations->method('byIds')->with([10])->willReturn([10 => $parent]);
		$this->locations->expects($this->once())->method('insert')
			->willReturnCallback(static function (Location $l): Location {
				$l->setId(20);
				return $l;
			});
		$api = $this->service->create('office', ['code' => 'WH-S1', 'name' => 'Shelf 1', 'kind' => 'shelf', 'parentId' => 10]);
		$this->assertSame(10, $api['parentId']);
		$this->assertSame('Loc WH', $api['parentName']);
		$this->assertSame('Loc WH / Shelf 1', $api['pathLabel']);
	}

	public function testCreateChildUnderChildFailsDepthCap(): void
	{
		$child = $this->loc(11, 'WH-S1', 10);
		$this->locations->method('findById')->with(11)->willReturn($child);
		$this->expectException(ValidationException::class);
		$this->service->create('office', ['code' => 'WH-S1-B', 'name' => 'Bin', 'kind' => 'shelf', 'parentId' => 11]);
	}

	public function testCreateWithNonIntegerParentFails(): void
	{
		$this->expectException(ValidationException::class);
		$this->service->create('office', ['code' => 'X1', 'name' => 'x', 'parentId' => 'abc']);
	}

	public function testCreateWithFloatParentFails(): void
	{
		$this->expectException(ValidationException::class);
		$this->service->create('office', ['code' => 'X1', 'name' => 'x', 'parentId' => 2.5]);
	}

	public function testCreateWithNegativeParentIdFails(): void
	{
		$this->expectException(ValidationException::class);
		$this->service->create('office', ['code' => 'X1', 'name' => 'x', 'parentId' => -5]);
	}

	public function testZeroParentIdClearsToRoot(): void
	{
		$this->locations->method('findByCode')->willReturn(null);
		$this->locations->expects($this->once())->method('insert')
			->willReturnCallback(static function (Location $l): Location {
				$l->setId(20);
				return $l;
			});
		$this->locations->method('byIds')->with([])->willReturn([]);
		$api = $this->service->create('office', ['code' => 'X2', 'name' => 'x', 'parentId' => 0]);
		$this->assertNull($api['parentId']);
		$this->assertSame('x', $api['pathLabel']);
	}

	public function testUpdateSelfParentFails(): void
	{
		$loc = $this->loc(10, 'WH');
		$this->locations->method('lockById')->willReturn($loc);
		$this->expectException(ValidationException::class);
		$this->service->update('office', 10, ['parentId' => 10]);
	}

	public function testUpdateParentWithChildrenCannotBecomeChild(): void
	{
		$loc = $this->loc(10, 'WH');
		$otherRoot = $this->loc(20, 'WH2');
		$this->locations->method('lockById')->willReturn($loc);
		$this->locations->method('findById')->with(20)->willReturn($otherRoot);
		$this->locations->method('hasChildren')->with(10)->willReturn(true);
		$this->expectException(ValidationException::class);
		$this->service->update('office', 10, ['parentId' => 20]);
	}

	public function testReparentChildToAnotherRootAllowed(): void
	{
		$child = $this->loc(11, 'WH-S1', 10);
		$otherRoot = $this->loc(20, 'WH2');
		$this->locations->method('lockById')->willReturn($child);
		$this->locations->method('findById')->with(20)->willReturn($otherRoot);
		$this->locations->method('hasChildren')->with(11)->willReturn(false);
		$this->locations->method('update')->willReturnArgument(0);
		$this->locations->method('byIds')->willReturn([20 => $otherRoot]);
		$api = $this->service->update('office', 11, ['parentId' => 20]);
		$this->assertSame(20, $api['parentId']);
		$this->assertSame('Loc WH2 / Loc WH-S1', $api['pathLabel']);
	}

	public function testDeleteParentWithChildrenRefused(): void
	{
		$loc = $this->loc(10, 'WH');
		$this->locations->method('lockById')->willReturn($loc);
		$this->locations->method('hasChildren')->with(10)->willReturn(true);
		try {
			$this->service->delete('office', 10);
			$this->fail('expected ConflictException');
		} catch (ConflictException $e) {
			$this->assertSame('location_has_children', $e->getMessage());
		}
	}

	public function testPathLabelMarksInactiveParent(): void
	{
		$parent = $this->loc(10, 'WH', null, false);
		$child = $this->loc(11, 'WH-S1', 10);
		$this->locations->method('findById')->willReturn($child);
		$this->locations->method('byIds')->with([10])->willReturn([10 => $parent]);
		$api = $this->service->get('office', 11);
		// Child keeps working under an inactive parent; the flag lets
		// clients render the "(inactive)" suffix (spec: deactivation
		// never cascades to children).
		$this->assertFalse($api['parentActive']);
		$this->assertSame('Loc WH / Loc WH-S1', $api['pathLabel']);
	}

	public function testPathLabelWithUnicodeNames(): void
	{
		$parent = $this->loc(10, 'WH-MS');
		$parent->setName('Lager München–Süd');
		$this->locations->method('findById')->willReturn($parent);
		$this->locations->method('findByCode')->willReturn(null);
		$this->locations->method('byIds')->willReturn([10 => $parent]);
		$this->locations->method('insert')->willReturnCallback(static function (Location $l): Location {
			$l->setId(20);
			return $l;
		});
		$api = $this->service->create('office', [
			'code' => 'REG-1', 'name' => 'Regal Üß', 'kind' => 'shelf', 'parentId' => 10,
		]);
		$this->assertSame('Lager München–Süd / Regal Üß', $api['pathLabel']);
	}

	public function testPathLabelRootIsPlainName(): void
	{
		$root = $this->loc(10, 'WH');
		$this->locations->method('findById')->willReturn($root);
		$this->locations->method('byIds')->with([])->willReturn([]);
		$api = $this->service->get('office', 10);
		$this->assertSame('Loc WH', $api['pathLabel']);
		$this->assertNull($api['parentName']);
	}
}
