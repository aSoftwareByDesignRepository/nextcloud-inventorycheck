<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Controller;

use OCA\InventoryCheck\Controller\MobileController;
use OCA\InventoryCheck\Db\Item;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\Lot;
use OCA\InventoryCheck\Db\LotMapper;
use OCA\InventoryCheck\Db\MovementMapper;
use OCA\InventoryCheck\Db\ScanDevice;
use OCA\InventoryCheck\Exception\MobileGateException;
use OCA\InventoryCheck\Exception\PermissionDeniedException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\BalanceService;
use OCA\InventoryCheck\Service\Clock;
use OCA\InventoryCheck\Service\CycleCountService;
use OCA\InventoryCheck\Service\DevicePairingService;
use OCA\InventoryCheck\Service\ItemPhotoService;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\LicenseService;
use OCA\InventoryCheck\Service\LocationAclService;
use OCA\InventoryCheck\Service\LocationFavouriteService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\LotService;
use OCA\InventoryCheck\Service\MobileGateService;
use OCA\InventoryCheck\Service\MovementService;
use OCA\InventoryCheck\Service\ProductLookupService;
use OCA\InventoryCheck\Service\ReportService;
use OCA\InventoryCheck\Service\RestockConfig;
use OCA\InventoryCheck\Service\ScanIdempotencyService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * IV-COMPANION-TODAY (C-3) endpoint pins:
 * - GET /mobile/v1/today is a money surface — session + office gate, device
 *   tokens and field seats refused (NN-01, same ladder as lowStock).
 * - GET /mobile/v1/lots/expiring is qty-only for every gated caller; `total`
 *   is a limit+1 lower bound so the client can offer "show all".
 *
 * LotService is final — the tests compose a real one from mocked mappers so
 * the actor-scope and zero-qty rules run through production code.
 */
final class MobileTodayTest extends TestCase
{
	private const NOW = 1800000000;

	/** @var IRequest&MockObject */ private IRequest $request;
	/** @var MobileGateService&MockObject */ private MobileGateService $gate;
	/** @var LicenseService&MockObject */ private LicenseService $license;
	/** @var AccessControlService&MockObject */ private AccessControlService $access;
	/** @var IUserSession&MockObject */ private IUserSession $userSession;
	/** @var ReportService&MockObject */ private ReportService $reports;
	/** @var Clock&MockObject */ private Clock $clock;
	/** @var LotMapper&MockObject */ private LotMapper $lotMapper;
	/** @var MovementMapper&MockObject */ private MovementMapper $movementMapper;
	/** @var ItemMapper&MockObject */ private ItemMapper $itemMapper;
	/** @var LocationAclService&MockObject */ private LocationAclService $locationAcl;
	private LotService $lotService;
	private MobileController $controller;

	protected function setUp(): void
	{
		$this->request = $this->createMock(IRequest::class);
		$this->gate = $this->createMock(MobileGateService::class);
		$this->license = $this->createMock(LicenseService::class);
		$this->access = $this->createMock(AccessControlService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->reports = $this->createMock(ReportService::class);
		$this->clock = $this->createMock(Clock::class);
		$this->clock->method('now')->willReturn(self::NOW);
		$this->lotMapper = $this->createMock(LotMapper::class);
		$this->movementMapper = $this->createMock(MovementMapper::class);
		$this->itemMapper = $this->createMock(ItemMapper::class);
		$this->locationAcl = $this->createMock(LocationAclService::class);
		$cfg = $this->createMock(IConfig::class);
		$cfg->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') =>
				$key === RestockConfig::KEY_EXPIRY_WARN_DAYS ? '30' : $default,
		);
		$this->lotService = new LotService(
			$this->lotMapper,
			$this->movementMapper,
			$this->itemMapper,
			$this->locationAcl,
			new RestockConfig($cfg),
			$this->clock,
		);
		$this->controller = new MobileController(
			$this->request,
			$this->gate,
			$this->license,
			$this->createMock(DevicePairingService::class),
			$this->createMock(ItemService::class),
			$this->createMock(LocationService::class),
			$this->createMock(BalanceService::class),
			$this->createMock(MovementService::class),
			$this->access,
			$this->createMock(LocationFavouriteService::class),
			$this->createMock(CycleCountService::class),
			$this->createMock(ItemPhotoService::class),
			$this->createMock(ScanIdempotencyService::class),
			$this->createMock(ProductLookupService::class),
			$this->userSession,
			$this->createMock(ISession::class),
			$this->createMock(IConfig::class),
			$this->createMock(IL10N::class),
			null,
			null,
			null,
			null,
			$this->reports,
			$this->clock,
			$this->lotService,
		);
	}

	private function asSessionUser(string $uid): void
	{
		$this->request->method('getHeader')->willReturn('');
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	private function asDevice(int $id): void
	{
		$this->request->method('getHeader')->willReturn('device-token');
		$device = new ScanDevice();
		$device->setId($id);
		$device->setActive(true);
		$this->license->method('hashSecret')->willReturn('hash');
		$this->license->method('findDeviceByTokenHash')->willReturn($device);
	}

	private function lot(int $id, int $itemId, string $code, int $expiresAt): Lot
	{
		$lot = new Lot();
		$lot->setId($id);
		$lot->setItemId($itemId);
		$lot->setLotCode($code);
		$lot->setExpiresAt($expiresAt);
		return $lot;
	}

	public function testTodayUnauthenticatedGetsAuthRequired(): void
	{
		$this->request->method('getHeader')->willReturn('');
		$this->userSession->method('getUser')->willReturn(null);
		$this->gate->expects(self::never())->method('assertGate');
		$this->reports->expects(self::never())->method('valuation');
		$this->expectException(MobileGateException::class);
		$this->expectExceptionMessage('auth_required');
		$this->controller->today();
	}

	public function testTodayDeviceTokenRefused(): void
	{
		$this->asDevice(7);
		$this->gate->expects(self::never())->method('assertGate');
		$this->reports->expects(self::never())->method('valuation');
		$this->expectException(MobileGateException::class);
		$this->expectExceptionMessage('auth_required');
		$this->controller->today();
	}

	public function testTodayNonOfficeSeatRefused(): void
	{
		$this->asSessionUser('alice');
		$this->access->method('requireOffice')->willThrowException(new PermissionDeniedException());
		$this->reports->expects(self::never())->method('valuation');
		$this->expectException(PermissionDeniedException::class);
		$this->controller->today();
	}

	public function testTodayReturnsChefViewEstimates(): void
	{
		$this->asSessionUser('boss');
		$this->gate->expects(self::once())->method('assertGate')->with('boss', null);
		$this->access->expects(self::once())->method('requireOffice')->with('boss');
		$this->clock->method('now')->willReturn(self::NOW);
		$this->clock->method('todayYmd')->willReturn('2027-01-14');
		$dayStart = strtotime('2027-01-14 00:00:00 UTC');
		$this->reports->expects(self::once())->method('valuation')
			->willReturn(['isEstimate' => true, 'rows' => [], 'totalMinor' => 123456, 'unpricedCount' => 2]);
		$this->reports->expects(self::exactly(2))->method('movementValue')
			->willReturnCallback(function (int $from, int $to) use ($dayStart) {
				self::assertSame(self::NOW, $to);
				return $from === $dayStart
					? ['isEstimate' => true, 'from' => $from, 'to' => $to, 'spendMinor' => 4200, 'unpricedReceiveCount' => 0]
					: ['isEstimate' => true, 'from' => $from, 'to' => $to, 'spendMinor' => 98700, 'unpricedReceiveCount' => 1];
			});
		$data = $this->controller->today()->getData();
		$chef = $data['chefView'];
		self::assertTrue($chef['isEstimate']);
		self::assertSame(123456, $chef['estStockValueMinor']);
		self::assertSame(2, $chef['unpricedCount']);
		self::assertSame(4200, $chef['spendTodayMinor']);
		self::assertSame(98700, $chef['spend30dMinor']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $data['serverNow']);
	}

	public function testLotsExpiringUnauthenticatedGetsAuthRequired(): void
	{
		$this->request->method('getHeader')->willReturn('');
		$this->userSession->method('getUser')->willReturn(null);
		$this->gate->expects(self::never())->method('assertGate');
		$this->lotMapper->expects(self::never())->method('expiringWithin');
		$this->expectException(MobileGateException::class);
		$this->controller->lotsExpiring();
	}

	public function testLotsExpiringCapsDataAndReportsLowerBoundTotal(): void
	{
		$this->asSessionUser('alice');
		$this->gate->expects(self::once())->method('assertGate')->with('alice', null);
		$this->request->method('getParam')->willReturnCallback(
			static fn ($k, $d = null) => $k === 'limit' ? '5' : $d,
		);
		$this->locationAcl->expects(self::once())->method('visibleLocationIds')
			->with('alice')->willReturn(null);
		// limit+1 probe: 6 in-stock lots for limit=5 → total reports the bound.
		$lots = [];
		$qtyMap = [];
		foreach (range(1, 6) as $i) {
			$lots[] = $this->lot($i, 1, 'L' . $i, self::NOW + 4 * 86400);
			$qtyMap[1]['L' . $i] = 3;
		}
		$this->lotMapper->expects(self::once())->method('expiringWithin')->willReturn($lots);
		$this->movementMapper->method('sumQtyDeltaByItemLotMap')->willReturn($qtyMap);
		$item = new Item();
		$item->setId(1);
		$item->setName('Milk');
		$item->setSku('MLK');
		$this->itemMapper->method('findById')->willReturn($item);
		$data = $this->controller->lotsExpiring()->getData();
		self::assertCount(5, $data['data']);
		self::assertSame(6, $data['total']);
		self::assertSame('Milk', $data['data'][0]['itemName']);
		// Qty-only contract: the feed must never smuggle money fields (NN-01 parity).
		foreach ($data['data'] as $row) {
			foreach (array_keys($row) as $key) {
				self::assertStringNotContainsStringIgnoringCase('price', (string)$key);
				self::assertStringNotContainsStringIgnoringCase('cost', (string)$key);
				self::assertStringNotContainsStringIgnoringCase('value', (string)$key);
			}
		}
	}

	public function testLotsExpiringDeviceCallerUsesDeviceActorScope(): void
	{
		$this->asDevice(9);
		$this->gate->expects(self::once())->method('assertGate')->with(null, self::isInstanceOf(ScanDevice::class));
		$this->request->method('getParam')->willReturnCallback(
			static fn ($k, $d = null) => $d,
		);
		// The device actor scope is what really matters — pinned through the
		// real service, not a mocked method signature.
		$this->locationAcl->expects(self::once())->method('visibleLocationIds')
			->with('device:9')->willReturn([3]);
		$this->lotMapper->method('expiringWithin')->willReturn([$this->lot(1, 1, 'L1', self::NOW + 86400)]);
		$this->movementMapper->expects(self::once())->method('sumQtyDeltaByItemLotMap')
			->with([1], [3])->willReturn([1 => ['L1' => 0]]);
		$data = $this->controller->lotsExpiring()->getData();
		// qty 0 → NN-04 zero-noise is filtered before the wire.
		self::assertSame([], $data['data']);
		self::assertSame(0, $data['total']);
	}

	public function testItemLotsUnauthenticatedGetsAuthRequired(): void
	{
		$this->request->method('getHeader')->willReturn('');
		$this->userSession->method('getUser')->willReturn(null);
		$this->gate->expects(self::never())->method('assertGate');
		$this->itemMapper->expects(self::never())->method('findById');
		$this->expectException(MobileGateException::class);
		$this->controller->itemLots(1);
	}

	public function testItemLotsReturnsFefoRowsForSessionUser(): void
	{
		$this->asSessionUser('alice');
		$this->gate->expects(self::once())->method('assertGate')->with('alice', null);
		$this->request->method('getParam')->willReturnCallback(
			static fn ($k, $d = null) => $d,
		);
		$item = new Item();
		$item->setId(5);
		$this->itemMapper->expects(self::once())->method('findById')->with(5)->willReturn($item);
		$this->locationAcl->expects(self::once())->method('visibleLocationIds')
			->with('alice')->willReturn(null);
		$this->movementMapper->expects(self::once())->method('sumQtyDeltaByLotForItem')
			->with(5, null)->willReturn(['LA' => 2, 'LB' => 4]);
		$this->lotMapper->expects(self::once())->method('findByItem')->with(5)
			->willReturn([
				$this->lot(1, 5, 'LA', self::NOW + 10 * 86400),
				$this->lot(2, 5, 'LB', self::NOW + 2 * 86400),
			]);
		$data = $this->controller->itemLots(5)->getData();
		self::assertCount(2, $data['data']);
		// FEFO: the earlier-expiry lot leads; qty data only (NN-01 parity).
		self::assertSame('LB', $data['data'][0]['lotCode']);
		self::assertSame(4, $data['data'][0]['qty']);
		foreach ($data['data'] as $row) {
			foreach (array_keys($row) as $key) {
				self::assertStringNotContainsStringIgnoringCase('price', (string)$key);
				self::assertStringNotContainsStringIgnoringCase('cost', (string)$key);
			}
		}
	}

	public function testItemLotsDeviceLocationFilterCollapsesToUniform404(): void
	{
		$this->asDevice(9);
		$this->gate->expects(self::once())->method('assertGate')->with(null, self::isInstanceOf(ScanDevice::class));
		$this->request->method('getParam')->willReturnCallback(
			static fn ($k, $d = null) => $k === 'locationId' ? '8' : $d,
		);
		$item = new Item();
		$item->setId(5);
		$this->itemMapper->method('findById')->willReturn($item);
		// device:9 cannot see location 8 — the service collapses to uniform
		// NotFound rather than leaking the location's existence.
		$this->locationAcl->expects(self::once())->method('visibleLocationIds')
			->with('device:9')->willReturn([3]);
		$this->lotMapper->expects(self::never())->method('findByItem');
		$this->expectException(\OCA\InventoryCheck\Exception\NotFoundException::class);
		$this->expectExceptionMessage('unknown_location');
		$this->controller->itemLots(5);
	}

	public function testItemLotsUnknownItemUniform404(): void
	{
		$this->asSessionUser('alice');
		$this->gate->expects(self::once())->method('assertGate');
		$this->request->method('getParam')->willReturnCallback(
			static fn ($k, $d = null) => $d,
		);
		$this->itemMapper->expects(self::once())->method('findById')->with(999)
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('none'));
		$this->locationAcl->expects(self::never())->method('visibleLocationIds');
		$this->expectException(\OCA\InventoryCheck\Exception\NotFoundException::class);
		$this->expectExceptionMessage('unknown_item');
		$this->controller->itemLots(999);
	}
}
