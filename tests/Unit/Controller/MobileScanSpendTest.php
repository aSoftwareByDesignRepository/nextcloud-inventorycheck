<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Controller;

use OCA\InventoryCheck\Controller\MobileController;
use OCA\InventoryCheck\Db\ScanDevice;
use OCA\InventoryCheck\Exception\MobileGateException;
use OCA\InventoryCheck\Exception\PermissionDeniedException;
use OCA\InventoryCheck\Exception\ValidationException;
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
use OCA\InventoryCheck\Service\ProductLookupService;
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
 * Behavioral pins for the receive-spend (F-06 mobile parity) and the
 * mobile low-stock endpoint: validation inside the idempotency claim,
 * money never reaching non-office callers.
 */
final class MobileScanSpendTest extends TestCase
{
	/** @var IRequest&MockObject */ private IRequest $request;
	/** @var MobileGateService&MockObject */ private MobileGateService $gate;
	/** @var LicenseService&MockObject */ private LicenseService $license;
	/** @var MovementService&MockObject */ private MovementService $movements;
	/** @var AccessControlService&MockObject */ private AccessControlService $access;
	/** @var ScanIdempotencyService&MockObject */ private ScanIdempotencyService $idem;
	/** @var IUserSession&MockObject */ private IUserSession $userSession;
	/** @var LowStockService&MockObject */ private LowStockService $lowStock;
	private MobileController $controller;

	protected function setUp(): void
	{
		$this->request = $this->createMock(IRequest::class);
		$this->gate = $this->createMock(MobileGateService::class);
		$this->license = $this->createMock(LicenseService::class);
		$this->movements = $this->createMock(MovementService::class);
		$this->access = $this->createMock(AccessControlService::class);
		$this->idem = $this->createMock(ScanIdempotencyService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->lowStock = $this->createMock(LowStockService::class);
		$this->controller = new MobileController(
			$this->request,
			$this->gate,
			$this->license,
			$this->createMock(DevicePairingService::class),
			$this->createMock(ItemService::class),
			$this->createMock(LocationService::class),
			$this->createMock(BalanceService::class),
			$this->movements,
			$this->access,
			$this->createMock(LocationFavouriteService::class),
			$this->createMock(CycleCountService::class),
			$this->createMock(ItemPhotoService::class),
			$this->idem,
			$this->createMock(ProductLookupService::class),
			$this->userSession,
			$this->createMock(ISession::class),
			$this->createMock(IConfig::class),
			$this->createMock(IL10N::class),
			null,
			null,
			$this->lowStock,
		);
	}

	private function asSessionUser(string $uid): void
	{
		$this->request->method('getHeader')->willReturn('');
		$this->request->method('passesCSRFCheck')->willReturn(true);
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

	private function idempotencyClaim(string $crid = 'crid-1'): void
	{
		$this->idem->method('normalizeClientRequestId')->willReturnArgument(0);
		$this->idem->method('fingerprintFromParams')->willReturn('hash1');
		$this->idem->method('lookup')->willReturn([
			'status' => 'missing',
			'response' => null,
			'payloadHash' => null,
		]);
		$this->idem->method('tryClaim')->willReturn(true);
	}

	public function testOfficeReceiveForwardsSpendAndGetsBudgetBlock(): void
	{
		$this->asSessionUser('alice');
		$this->access->method('isOffice')->willReturn(true);
		$this->idempotencyClaim();
		$this->request->method('getParams')->willReturn([
			'code' => 'SKU-1',
			'kind' => 'receive',
			'locationId' => 3,
			'clientRequestId' => 'crid-1',
			'spendMinor' => 12840,
		]);
		$this->movements->expects(self::once())
			->method('scan')
			->willReturnCallback(function (...$args) {
				$spend = $args[count($args) - 1];
				self::assertSame(12840, $spend, 'spendMinor forwarded as trailing int');
				self::assertTrue($args[8], 'asOffice true for office seat');
				return ['movements' => [['itemId' => 5, 'qty' => 1]], 'balances' => []];
			});
		$this->lowStock->expects(self::once())
			->method('budgetForItem')
			->with('alice', 5)
			->willReturn([
				'category' => 'Supplies',
				'budgetMinor' => 100000,
				'spentMtdMinor' => 30000,
				'remainingMinor' => 70000,
				'emergencyMinor' => 5000,
			]);
		$this->idem->expects(self::once())->method('complete');

		$out = $this->controller->scan()->getData();
		self::assertSame(70000, $out['budget']['remainingMinor']);
		self::assertSame('Supplies', $out['budget']['category']);
	}

	public function testDigitStringSpendIsCoerced(): void
	{
		$this->asSessionUser('alice');
		$this->access->method('isOffice')->willReturn(true);
		$this->idempotencyClaim();
		$this->request->method('getParams')->willReturn([
			'code' => 'SKU-1',
			'kind' => 'receive',
			'locationId' => 3,
			'spendMinor' => '1299',
		]);
		$this->movements->expects(self::once())
			->method('scan')
			->willReturnCallback(function (...$args) {
				self::assertSame(1299, $args[count($args) - 1]);
				return ['movements' => [['itemId' => 1]], 'balances' => []];
			});
		$this->lowStock->method('budgetForItem')->willReturn(null);
		$this->controller->scan();
	}

	public function testSpendOnNonReceiveIsRejectedAndClaimReleased(): void
	{
		$this->asSessionUser('alice');
		$this->access->method('isOffice')->willReturn(true);
		$this->idempotencyClaim();
		$this->request->method('getParams')->willReturn([
			'code' => 'SKU-1',
			'kind' => 'issue',
			'locationId' => 3,
			'clientRequestId' => 'crid-1',
			'spendMinor' => 500,
		]);
		$this->movements->expects(self::never())->method('scan');
		$this->idem->expects(self::once())
			->method('release')
			->with('alice', 'crid-1');
		$this->expectException(ValidationException::class);
		$this->controller->scan();
	}

	public function testGarbageSpendIsRejectedAndClaimReleased(): void
	{
		$this->asSessionUser('alice');
		$this->access->method('isOffice')->willReturn(true);
		$this->idempotencyClaim();
		$this->request->method('getParams')->willReturn([
			'code' => 'SKU-1',
			'kind' => 'receive',
			'locationId' => 3,
			'clientRequestId' => 'crid-1',
			'spendMinor' => 'abc',
		]);
		$this->movements->expects(self::never())->method('scan');
		$this->idem->expects(self::once())
			->method('release')
			->with('alice', 'crid-1');
		$this->expectException(ValidationException::class);
		$this->controller->scan();
	}

	public function testDeviceCallerCannotReceiveAndClaimReleased(): void
	{
		$this->asDevice(7);
		$this->access->method('isOffice')->willReturn(false);
		$this->idempotencyClaim();
		$this->request->method('getParams')->willReturn([
			'code' => 'SKU-1',
			'kind' => 'receive',
			'locationId' => 3,
			'clientRequestId' => 'crid-1',
			'spendMinor' => 500,
		]);
		$this->movements->expects(self::once())
			->method('scan')
			->willReturnCallback(function (...$args) {
				self::assertFalse($args[8], 'asOffice must be false for devices');
				self::assertSame('device:7', $args[0]);
				throw new PermissionDeniedException();
			});
		$this->idem->expects(self::once())->method('release')->with('device:7', 'crid-1');
		$this->expectException(PermissionDeniedException::class);
		$this->controller->scan();
	}

	public function testLowStockReturnsFormattedPageForOffice(): void
	{
		$this->asSessionUser('alice');
		$this->access->expects(self::once())->method('requireOffice')->with('alice');
		$this->request->method('getParam')->willReturn(null);
		$this->lowStock->expects(self::once())
			->method('list')
			->willReturn([
				'data' => [['item' => ['id' => 5, 'name' => 'Gloves', 'sku' => 'GLV'], 'totalQty' => 2, 'reorderLevel' => 5]],
				'total' => 1,
				'limit' => 200,
				'offset' => 0,
			]);
		$out = $this->controller->lowStock()->getData();
		self::assertSame(1, $out['total']);
		self::assertSame('Gloves', $out['data'][0]['item']['name']);
	}

	public function testLowStockDeniesNonOfficeBeforeQuery(): void
	{
		$this->asSessionUser('bob');
		$this->access->expects(self::once())
			->method('requireOffice')
			->with('bob')
			->willThrowException(new PermissionDeniedException());
		$this->lowStock->expects(self::never())->method('list');
		$this->expectException(PermissionDeniedException::class);
		$this->controller->lowStock();
	}

	public function testLowStockDeniesDeviceToken(): void
	{
		$this->asDevice(9);
		$this->lowStock->expects(self::never())->method('list');
		$this->expectException(MobileGateException::class);
		$this->controller->lowStock();
	}
}
