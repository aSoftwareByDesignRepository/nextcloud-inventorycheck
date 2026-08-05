<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Controller\MobileController;
use OCA\InventoryCheck\Exception\AppAccessDeniedException;
use OCA\InventoryCheck\Exception\MobileGateException;
use OCA\InventoryCheck\Middleware\AppAccessMiddleware;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\BalanceService;
use OCA\InventoryCheck\Service\CycleCountService;
use OCA\InventoryCheck\Service\DevicePairingService;
use OCA\InventoryCheck\Service\ItemPhotoService;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\LicenseService;
use OCA\InventoryCheck\Service\LocationFavouriteService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\MobileGateService;
use OCA\InventoryCheck\Service\MovementService;
use OCA\InventoryCheck\Tests\Support\Iv2TestSigning;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use Test\TestCase;

/**
 * AC-17 — mobile gate HTTP status ladder via MobileController + middleware.
 *
 * @group DB
 */
final class MobileGateLadderIntegrationTest extends TestCase
{
	private const USER = 'iv_mob_gate_u';
	private const DENIED = 'iv_mob_gate_d';
	private const PASSWORD = 'Iv-MobGate-9xK!';

	/** @var array<string, string> */
	private array $prevConfig = [];
	/** @var string|false */
	private $prevEnv;

	protected function setUp(): void
	{
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud runtime required');
		}
		\OC_User::setIncognitoMode(false);
		$this->prevEnv = getenv('IV_VENDOR_PUBLIC_KEY_B64');
		putenv('IV_VENDOR_PUBLIC_KEY_B64=' . Iv2TestSigning::publicKeyB64());

		$config = Server::get(IConfig::class);
		foreach ([
			AccessControlService::KEY_ACCESS_RESTRICTION,
			AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS,
			AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS,
			AccessControlService::KEY_APP_ADMINS,
			AccessControlService::KEY_OFFICE_USER_IDS,
			AccessControlService::KEY_OFFICE_GROUP_IDS,
		] as $key) {
			$this->prevConfig[$key] = $config->getAppValue(Application::APP_ID, $key, '');
		}

		$this->deleteUsers();
		$um = Server::get(IUserManager::class);
		$um->createUser(self::USER, self::PASSWORD);
		$um->createUser(self::DENIED, self::PASSWORD);

		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_RESTRICTION, '1');
		$config->setAppValue(
			Application::APP_ID,
			AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS,
			json_encode([self::USER], JSON_THROW_ON_ERROR),
		);
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS, '[]');
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_APP_ADMINS, '[]');
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_OFFICE_USER_IDS, '[]');
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_OFFICE_GROUP_IDS, '[]');

		$license = (new Application())->getContainer()->get(LicenseService::class);
		$license->apply('admin', Iv2TestSigning::signPayload([
			'v' => 2,
			'product' => 'inventorycheck',
			'customerId' => 'mob-gate-' . bin2hex(random_bytes(2)),
			'issuedAt' => '2026-07-24',
			'validUntil' => '2099-01-01',
			'mobileSeats' => 2,
			'scanDevices' => 2,
		]));
		$license->assignSeat('admin', self::USER);
	}

	protected function tearDown(): void
	{
		if ($this->prevEnv === false) {
			putenv('IV_VENDOR_PUBLIC_KEY_B64');
		} else {
			putenv('IV_VENDOR_PUBLIC_KEY_B64=' . $this->prevEnv);
		}
		if (!isset(\OC::$server)) {
			return;
		}
		$config = Server::get(IConfig::class);
		foreach ($this->prevConfig as $key => $value) {
			if ($value === '') {
				$config->deleteAppValue(Application::APP_ID, $key);
			} else {
				$config->setAppValue(Application::APP_ID, $key, $value);
			}
		}
		$this->deleteUsers();
		Server::get(IUserSession::class)->setUser(null);
	}

	private function deleteUsers(): void
	{
		$um = Server::get(IUserManager::class);
		foreach ([self::USER, self::DENIED] as $uid) {
			if ($um->userExists($uid)) {
				$um->get($uid)?->delete();
			}
		}
	}

	private function mobileMiddleware(): AppAccessMiddleware
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn('/apps/inventorycheck/mobile/v1/bootstrap');
		$request->method('getMethod')->willReturn('GET');
		return new AppAccessMiddleware(
			Server::get(IUserSession::class),
			Server::get(AccessControlService::class),
			$request,
			Server::get(\OCP\IURLGenerator::class),
			Server::get(\OCP\L10N\IFactory::class),
			Server::get(\OCP\IConfig::class),
		);
	}

	/**
	 * @param callable(): JSONResponse $call
	 */
	private function invoke(object $controller, string $method, callable $call): JSONResponse
	{
		$middleware = $this->mobileMiddleware();
		$middleware->beforeController($controller, $method);
		try {
			$response = $call();
			$this->assertInstanceOf(JSONResponse::class, $response);
			return $response;
		} catch (\Exception $e) {
			$response = $middleware->afterException($controller, $method, $e);
			$this->assertInstanceOf(JSONResponse::class, $response);
			return $response;
		}
	}

	private function makeMobileController(IRequest $request): MobileController
	{
		return new MobileController(
			$request,
			Server::get(MobileGateService::class),
			Server::get(LicenseService::class),
			Server::get(DevicePairingService::class),
			Server::get(ItemService::class),
			Server::get(LocationService::class),
			Server::get(BalanceService::class),
			Server::get(MovementService::class),
			Server::get(AccessControlService::class),
			Server::get(LocationFavouriteService::class),
			Server::get(CycleCountService::class),
			Server::get(ItemPhotoService::class),
			Server::get(IUserSession::class),
			Server::get(IConfig::class),
		);
	}

	private function controllerWithoutAuth(): MobileController
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('X-IV-Device-Token')->willReturn('');
		Server::get(IUserSession::class)->setUser(null);
		return $this->makeMobileController($request);
	}

	private function controllerAsUser(string $uid): MobileController
	{
		$user = Server::get(IUserManager::class)->get($uid);
		$this->assertNotNull($user);
		Server::get(IUserSession::class)->setUser($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('X-IV-Device-Token')->willReturn('');
		return $this->makeMobileController($request);
	}

	public function testBootstrapWithoutAuthReturns401(): void
	{
		$controller = $this->controllerWithoutAuth();
		$response = $this->invoke($controller, 'bootstrap', static fn () => $controller->bootstrap());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('auth_required', $response->getData()['error']['code']);
	}

	public function testBootstrapDeniedUserReturns403(): void
	{
		$controller = $this->controllerAsUser(self::DENIED);
		$response = $this->invoke($controller, 'bootstrap', static fn () => $controller->bootstrap());
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('app_access_denied', $response->getData()['error']['code']);
	}

	public function testBootstrapAllowedUserReturnsEnvelopeNotStock(): void
	{
		$controller = $this->controllerAsUser(self::USER);
		$response = $this->invoke($controller, 'bootstrap', static fn () => $controller->bootstrap());
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertArrayHasKey('licensing', $data);
		$this->assertNotNull($data['licensing']);
		$this->assertSame('IV2', $data['licensing']['format']);
		$this->assertTrue($data['seatAssigned']);
		$this->assertSame(self::USER, $data['user']);
	}

	public function testByCodeWithoutAuthReturns401NotSeatRequired(): void
	{
		$controller = $this->controllerWithoutAuth();
		$response = $this->invoke($controller, 'byCode', static fn () => $controller->byCode('FILTER-42'));
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('auth_required', $response->getData()['error']['code']);
	}

	public function testAnonymousCannotCallBootstrapViaGateExceptionShape(): void
	{
		Server::get(IUserSession::class)->setUser(null);
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('');
		$controller = $this->makeMobileController($request);
		try {
			$controller->bootstrap();
			$this->fail('expected auth_required');
		} catch (MobileGateException $e) {
			$this->assertSame('auth_required', $e->getErrorCode());
		} catch (AppAccessDeniedException) {
			$this->fail('anonymous must fail at rung 1, not rung 2');
		}
	}

	public function testGarbageDeviceTokenReturns401(): void
	{
		Server::get(IUserSession::class)->setUser(null);
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('X-IV-Device-Token')->willReturn(str_repeat('a', 64));
		$controller = $this->makeMobileController($request);
		$response = $this->invoke($controller, 'bootstrap', static fn () => $controller->bootstrap());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('auth_required', $response->getData()['error']['code']);
	}

	public function testDeactivatedDeviceTokenReturns402DeviceRequired(): void
	{
		$license = Server::get(LicenseService::class);
		$created = $license->createDevice('admin', 'gate-ladder-scanner');
		$id = (int)$created['device']['id'];
		$paired = Server::get(DevicePairingService::class)->pair($created['pairCode']);
		$token = $paired['token'];
		$license->deactivateDevice($id);

		Server::get(IUserSession::class)->setUser(null);
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('X-IV-Device-Token')->willReturn($token);
		$controller = $this->makeMobileController($request);
		$response = $this->invoke($controller, 'bootstrap', static fn () => $controller->bootstrap());
		$this->assertSame(402, $response->getStatus());
		$this->assertSame('device_required', $response->getData()['error']['code']);
	}
}
