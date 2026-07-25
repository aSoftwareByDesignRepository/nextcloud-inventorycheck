<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Exception\ConflictException;
use OCA\InventoryCheck\Service\LicenseService;
use OCA\InventoryCheck\Tests\Support\Iv2TestSigning;
use OCP\IUserManager;
use OCP\Server;
use Test\TestCase;

/**
 * AC-15/AC-16: IV2 apply + seat/device limits.
 *
 * @group DB
 */
final class LicenseSeatsDevicesIntegrationTest extends TestCase
{
	private LicenseService $license;
	/** @var string|false */
	private $prevEnv;

	protected function setUp(): void
	{
		parent::setUp();
		$this->prevEnv = getenv('IV_VENDOR_PUBLIC_KEY_B64');
		putenv('IV_VENDOR_PUBLIC_KEY_B64=' . Iv2TestSigning::publicKeyB64());
		$app = new Application();
		$this->license = $app->getContainer()->get(LicenseService::class);
	}

	protected function tearDown(): void
	{
		if ($this->prevEnv === false) {
			putenv('IV_VENDOR_PUBLIC_KEY_B64');
		} else {
			putenv('IV_VENDOR_PUBLIC_KEY_B64=' . $this->prevEnv);
		}
		parent::tearDown();
	}

	public function testApplyAndSeatDeviceLimits(): void
	{
		$wire = Iv2TestSigning::signPayload([
			'v' => 2,
			'product' => 'inventorycheck',
			'customerId' => 'iv-test-' . bin2hex(random_bytes(2)),
			'issuedAt' => '2026-07-24',
			'validUntil' => '2099-01-01',
			'mobileSeats' => 1,
			'scanDevices' => 1,
		]);
		$status = $this->license->apply('admin', $wire);
		$this->assertSame(1, $status['seats']['limit']);
		$this->assertSame(1, $status['devices']['limit']);

		$users = Server::get(IUserManager::class);
		$u1 = 'ivseat1_' . bin2hex(random_bytes(2));
		$u2 = 'ivseat2_' . bin2hex(random_bytes(2));
		foreach ([$u1, $u2] as $uid) {
			if ($users->userExists($uid)) {
				$users->get($uid)?->delete();
			}
			$this->assertNotFalse($users->createUser($uid, bin2hex(random_bytes(8))));
		}

		try {
			// Clear leftover seats so limit=1 is meaningful across re-runs.
			foreach ($this->license->listSeats(200, 0)['data'] as $row) {
				$this->license->removeSeat((string)$row['uid']);
			}

			$first = $this->license->assignSeat('admin', $u1);
			$this->assertSame($u1, $first['uid']);
			// Idempotent re-assign
			$again = $this->license->assignSeat('admin', $u1);
			$this->assertSame($u1, $again['uid']);

			try {
				$this->license->assignSeat('admin', $u2);
				$this->fail('expected seat_limit_reached');
			} catch (ConflictException $e) {
				$this->assertSame('seat_limit_reached', $e->getErrorCode());
			}

			// Clear leftover active devices from prior runs so limit=1 is meaningful.
			foreach ($this->license->listDevices(200, 0)['data'] as $row) {
				if (!empty($row['active'])) {
					$this->license->deactivateDevice((int)$row['id']);
				}
			}

			$dev = $this->license->createDevice('admin', 'Scanner 1');
			$this->assertArrayHasKey('pairCode', $dev);
			$this->assertMatchesRegularExpression('/^[A-Z2-9]{8}$/', $dev['pairCode']);

			try {
				$this->license->createDevice('admin', 'Scanner 2');
				$this->fail('expected device_limit_reached');
			} catch (ConflictException $e) {
				$this->assertSame('device_limit_reached', $e->getErrorCode());
			}

			// Cleanup created device slot.
			$this->license->deactivateDevice((int)$dev['device']['id']);
		} finally {
			$this->license->removeSeat($u1);
			$this->license->removeSeat($u2);
			$users->get($u1)?->delete();
			$users->get($u2)?->delete();
		}
	}
}
