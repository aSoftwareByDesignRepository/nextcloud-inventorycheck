<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Controller;

use OCA\InventoryCheck\Controller\MobileController;
use OCA\InventoryCheck\Db\ScanDevice;
use OCA\InventoryCheck\Exception\MobileGateException;
use OCA\InventoryCheck\Exception\PermissionDeniedException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\BalanceService;
use OCA\InventoryCheck\Service\CycleCountService;
use OCA\InventoryCheck\Service\DevicePairingService;
use OCA\InventoryCheck\Service\ItemPhotoService;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\LicenseService;
use OCA\InventoryCheck\Service\LocationFavouriteService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\LowStockService;
use OCA\InventoryCheck\Service\MobileGateService;
use OCA\InventoryCheck\Service\MovementService;
use OCA\InventoryCheck\Service\Pagination;
use OCA\InventoryCheck\Service\ProductLookupService;
use OCA\InventoryCheck\Service\ScanIdempotencyService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Widget-snapshot endpoint pins (NH-P2): same session+office gate as
 * lowStock — device tokens refused — and the gate must run BEFORE the
 * per-user cache read so a revoked seat is never served a stale hit.
 */
final class MobileWidgetSnapshotTest extends TestCase
{
	/** @var IRequest&MockObject */ private IRequest $request;
	/** @var MobileGateService&MockObject */ private MobileGateService $gate;
	/** @var LicenseService&MockObject */ private LicenseService $license;
	/** @var AccessControlService&MockObject */ private AccessControlService $access;
	/** @var IUserSession&MockObject */ private IUserSession $userSession;
	/** @var LowStockService&MockObject */ private LowStockService $lowStock;
	/** @var ICacheFactory&MockObject */ private ICacheFactory $cacheFactory;
	/** @var ICache&MockObject */ private ICache $cache;
	private MobileController $controller;

	protected function setUp(): void
	{
		$this->request = $this->createMock(IRequest::class);
		$this->gate = $this->createMock(MobileGateService::class);
		$this->license = $this->createMock(LicenseService::class);
		$this->access = $this->createMock(AccessControlService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->lowStock = $this->createMock(LowStockService::class);
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->cache = $this->createMock(ICache::class);
		$this->cacheFactory->method('createDistributed')
			->with('inventorycheck_wsnap')
			->willReturn($this->cache);
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
			$this->lowStock,
			$this->cacheFactory,
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

	public function testUnauthenticatedGetsAuthRequired(): void
	{
		$this->request->method('getHeader')->willReturn('');
		$this->userSession->method('getUser')->willReturn(null);
		$this->gate->expects(self::never())->method('assertGate');
		$this->cache->expects(self::never())->method('get');
		$this->expectException(MobileGateException::class);
		$this->expectExceptionMessage('auth_required');
		$this->controller->widgetSnapshot();
	}

	public function testDeviceTokenRefusedLikeLowStock(): void
	{
		$this->asDevice(7);
		$this->gate->expects(self::never())->method('assertGate');
		$this->cache->expects(self::never())->method('get');
		$this->expectException(MobileGateException::class);
		$this->expectExceptionMessage('auth_required');
		$this->controller->widgetSnapshot();
	}

	public function testNonOfficeSeatRefused(): void
	{
		$this->asSessionUser('alice');
		$this->access->method('requireOffice')->willThrowException(new PermissionDeniedException());
		$this->cache->expects(self::never())->method('get');
		$this->expectException(PermissionDeniedException::class);
		$this->controller->widgetSnapshot();
	}

	public function testCacheMissComputesCachesAndReturnsSnapshot(): void
	{
		$this->asSessionUser('alice');
		$this->gate->expects(self::once())->method('assertGate')->with('alice', null);
		$this->access->expects(self::once())->method('requireOffice')->with('alice');
		$this->cache->method('get')->with('alice:lowStock')->willReturn(null);
		$status = ['data' => [], 'total' => 0];
		// Fixed doc — even hostile limit/offset params must not reach the
		// service (a cached hit could never disagree with a cold compute).
		$this->request->method('getParam')->willReturnCallback(
			static fn ($k) => $k === 'limit' ? 'abc' : ($k === 'offset' ? '-1' : null),
		);
		$this->lowStock->expects(self::once())->method('list')
			->with('alice', Pagination::DEFAULT_LIMIT, 0)->willReturn($status);
		$this->cache->expects(self::once())->method('set')->with(
			'alice:lowStock',
			self::callback(fn ($payload) => ($payload['status'] ?? null) === $status
				&& is_string($payload['serverNow'] ?? null)),
			60,
		);
		$data = $this->controller->widgetSnapshot()->getData();
		self::assertSame($status, $data['status']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+\-Z]/', $data['serverNow']);
	}

	public function testCacheHitStillRunsGateAndSkipsService(): void
	{
		$this->asSessionUser('alice');
		// The 60 s cache may only short-circuit the expensive list — never the
		// authorization chain. A revoked seat must not read a stale snapshot.
		$this->gate->expects(self::once())->method('assertGate')->with('alice', null);
		$this->access->expects(self::once())->method('requireOffice')->with('alice');
		$cached = ['status' => ['data' => [], 'total' => 0], 'serverNow' => '2026-11-07T17:00:00+00:00'];
		$this->cache->method('get')->with('alice:lowStock')->willReturn($cached);
		$this->lowStock->expects(self::never())->method('list');
		$this->cache->expects(self::never())->method('set');
		self::assertSame($cached, $this->controller->widgetSnapshot()->getData());
	}
}
