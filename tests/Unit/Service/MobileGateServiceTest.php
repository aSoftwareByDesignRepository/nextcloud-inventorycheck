<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Db\LicenseState;
use OCA\InventoryCheck\Db\MobileSeat;
use OCA\InventoryCheck\Db\ScanDevice;
use OCA\InventoryCheck\Exception\AppAccessDeniedException;
use OCA\InventoryCheck\Exception\MobileGateException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\Clock;
use OCA\InventoryCheck\Service\LicenseService;
use OCA\InventoryCheck\Service\MobileGateService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class MobileGateServiceTest extends TestCase
{
	private LicenseService $license;
	private AccessControlService $access;
	private Clock $clock;
	private IConfig $config;
	private MobileGateService $gate;

	protected function setUp(): void
	{
		parent::setUp();
		$this->license = $this->createMock(LicenseService::class);
		$this->access = $this->createMock(AccessControlService::class);
		$this->clock = $this->createMock(Clock::class);
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getAppValue')->willReturn('0');
		$this->clock->method('todayYmd')->willReturn('2026-07-24');
		$this->gate = new MobileGateService($this->license, $this->access, $this->clock, $this->config);
	}

	public function testAssertGateMissingLicense(): void
	{
		$this->access->method('canUseApp')->willReturn(true);
		$this->license->method('findSingletonState')->willReturn(null);
		$this->expectException(MobileGateException::class);
		try {
			$this->gate->assertGate('alice', null);
		} catch (MobileGateException $e) {
			$this->assertSame('license_missing', $e->getErrorCode());
			throw $e;
		}
	}

	public function testAssertGateSeatRequired(): void
	{
		$this->access->method('canUseApp')->willReturn(true);
		$state = $this->validState(2, 1);
		$this->license->method('findSingletonState')->willReturn($state);
		$this->license->method('findSeatByUid')->willReturn(null);
		$this->expectException(MobileGateException::class);
		try {
			$this->gate->assertGate('alice', null);
		} catch (MobileGateException $e) {
			$this->assertSame('seat_required', $e->getErrorCode());
			throw $e;
		}
	}

	public function testAssertGateAppAccessDenied(): void
	{
		$this->access->method('canUseApp')->willReturn(false);
		$this->access->method('denialReasonWhenCannotUseApp')->willReturn('restriction');
		$this->expectException(AppAccessDeniedException::class);
		$this->gate->assertGate('bob', null);
	}

	public function testAssertGateDeviceWithinLimitPasses(): void
	{
		$state = $this->validState(0, 2);
		$device = new ScanDevice();
		$device->setId(5);
		$device->setPairedAt(100);
		$device->setActive(true);
		$this->license->method('findSingletonState')->willReturn($state);
		$this->license->method('pairedActiveDevices')->willReturn([$device]);
		$this->gate->assertGate(null, $device);
		$this->addToAssertionCount(1);
	}

	public function testBootstrapReportsSeatFlags(): void
	{
		$state = $this->validState(1, 0);
		$seat = new MobileSeat();
		$seat->setId(9);
		$seat->setUid('alice');
		$seat->setAssignedAt(50);
		$this->license->method('findSingletonState')->willReturn($state);
		$this->license->method('findSeatByUid')->with('alice')->willReturn($seat);
		$this->license->method('allSeats')->willReturn([$seat]);
		$boot = $this->gate->bootstrap('alice', null);
		$this->assertTrue($boot['seatAssigned']);
		$this->assertTrue($boot['seatWithinLimit']);
		$this->assertFalse($boot['devicePaired']);
		$this->assertSame(LicenseService::MOBILE_APP_STATUS, $boot['mobileAppStatus']);
		$this->assertSame(4, $boot['companionApi']);
		$this->assertTrue($boot['capabilities']['locationByCode']);
		$this->assertTrue($boot['capabilities']['reasonCodes']);
		$this->assertArrayHasKey('reasonCodes', $boot);
		$this->assertTrue($boot['capabilities']['csv']);
		$this->assertTrue($boot['capabilities']['photos']);
		$this->assertTrue($boot['capabilities']['cycleCount']);
		$this->assertTrue($boot['capabilities']['bulkLabels']);
	}

	private function validState(int $seats, int $devices): LicenseState
	{
		$state = new LicenseState();
		$state->setValidUntil('2099-01-01');
		$state->setMobileSeats($seats);
		$state->setScanDevices($devices);
		$state->setPayloadB64('x');
		$state->setSignatureB64('y');
		return $state;
	}
}
