<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Controller\ItemController;
use OCA\InventoryCheck\Exception\AppAccessDeniedException;
use OCA\InventoryCheck\Exception\PermissionDeniedException;
use OCA\InventoryCheck\Middleware\AppAccessMiddleware;
use OCA\InventoryCheck\Service\AccessControlService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use Test\TestCase;

/**
 * AC-2: L2 gate — pages → access-denied template; /api/* → JSON 403.
 *
 * @group DB
 */
final class AppAccessGateIntegrationTest extends TestCase
{
	private const ALLOWED = 'iv_gate_allowed';
	private const DENIED = 'iv_gate_denied';
	private const PASSWORD = 'Iv-gate-pass-9xK!';

	/** @var array<string, string> */
	private array $prevConfig = [];

	private const KEYS = [
		AccessControlService::KEY_ACCESS_RESTRICTION,
		AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS,
		AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS,
		AccessControlService::KEY_APP_ADMINS,
		AccessControlService::KEY_OFFICE_USER_IDS,
		AccessControlService::KEY_OFFICE_GROUP_IDS,
	];

	protected function setUp(): void
	{
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud runtime required');
		}
		\OC_User::setIncognitoMode(false);
		$config = Server::get(IConfig::class);
		foreach (self::KEYS as $key) {
			$this->prevConfig[$key] = $config->getAppValue(Application::APP_ID, $key, '');
		}
		$this->deleteUsers();
	}

	protected function tearDown(): void
	{
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
		$userManager = Server::get(IUserManager::class);
		foreach ([self::ALLOWED, self::DENIED] as $uid) {
			if ($userManager->userExists($uid)) {
				$userManager->get($uid)?->delete();
			}
		}
	}

	public function testRestrictionBlocksUnlistedUserWithJsonEnvelope(): void
	{
		$userManager = Server::get(IUserManager::class);
		$userManager->createUser(self::ALLOWED, self::PASSWORD);
		$userManager->createUser(self::DENIED, self::PASSWORD);

		$config = Server::get(IConfig::class);
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_APP_ADMINS, '[]');
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_OFFICE_USER_IDS, '[]');
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_OFFICE_GROUP_IDS, '[]');
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_RESTRICTION, '1');
		$config->setAppValue(
			Application::APP_ID,
			AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS,
			json_encode([self::ALLOWED], JSON_THROW_ON_ERROR),
		);
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS, '[]');

		$denied = $userManager->get(self::DENIED);
		$this->assertNotNull($denied);
		$session = Server::get(IUserSession::class);
		$session->setUser($denied);

		$acl = Server::get(AccessControlService::class);
		$this->assertFalse($acl->canUseApp(self::DENIED));

		$controller = Server::get(ItemController::class);
		$middleware = $this->middlewareWithApiRequest();

		try {
			$middleware->beforeController($controller, 'index');
			$this->fail('Expected AppAccessDeniedException for restricted user');
		} catch (AppAccessDeniedException $exception) {
			$this->assertSame(AccessControlService::DENIAL_RESTRICTION, $exception->getDenialReason());
		}

		$response = $middleware->afterException(
			$controller,
			'index',
			new AppAccessDeniedException(AccessControlService::DENIAL_RESTRICTION),
		);
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('app_access_denied', $data['error']['code']);
		$this->assertNotSame('', (string)$data['error']['message']);
	}

	public function testRestrictionPageRouteReturnsAccessDeniedTemplate(): void
	{
		$userManager = Server::get(IUserManager::class);
		$userManager->createUser(self::DENIED, self::PASSWORD);

		$config = Server::get(IConfig::class);
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_APP_ADMINS, '[]');
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_RESTRICTION, '1');
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS, '[]');

		$session = Server::get(IUserSession::class);
		$session->setUser($userManager->get(self::DENIED));

		$controller = Server::get(\OCA\InventoryCheck\Controller\PageController::class);
		$middleware = $this->middlewareWithPageRequest();

		try {
			$middleware->beforeController($controller, 'dashboard');
			$this->fail('Expected AppAccessDeniedException on page');
		} catch (AppAccessDeniedException $e) {
			$response = $middleware->afterException($controller, 'dashboard', $e);
			$this->assertInstanceOf(TemplateResponse::class, $response);
			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
			$this->assertSame('access-denied', $response->getTemplateName());
		}
	}

	public function testAllowedUserAndAppAdminPassTheGate(): void
	{
		$userManager = Server::get(IUserManager::class);
		$userManager->createUser(self::ALLOWED, self::PASSWORD);

		$config = Server::get(IConfig::class);
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_RESTRICTION, '1');
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS, '[]');
		$config->setAppValue(
			Application::APP_ID,
			AccessControlService::KEY_APP_ADMINS,
			json_encode([self::ALLOWED], JSON_THROW_ON_ERROR),
		);

		$session = Server::get(IUserSession::class);
		$session->setUser($userManager->get(self::ALLOWED));

		$controller = Server::get(ItemController::class);
		$this->middlewareWithApiRequest()->beforeController($controller, 'index');
		$this->addToAssertionCount(1);
	}

	public function testFieldGetsPermissionDeniedEnvelopeForOfficeActions(): void
	{
		$userManager = Server::get(IUserManager::class);
		$userManager->createUser(self::DENIED, self::PASSWORD);

		$config = Server::get(IConfig::class);
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_ACCESS_RESTRICTION, '0');
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_OFFICE_USER_IDS, '[]');
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_OFFICE_GROUP_IDS, '[]');
		$config->setAppValue(Application::APP_ID, AccessControlService::KEY_APP_ADMINS, '[]');

		$acl = Server::get(AccessControlService::class);
		$this->assertTrue($acl->canUseApp(self::DENIED));
		$this->assertFalse($acl->isOffice(self::DENIED));

		try {
			$acl->requireOffice(self::DENIED);
			$this->fail('Field must not pass office checks');
		} catch (PermissionDeniedException $e) {
			$controller = Server::get(ItemController::class);
			$response = $this->middlewareWithApiRequest()->afterException($controller, 'create', $e);
			$this->assertInstanceOf(JSONResponse::class, $response);
			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
			$this->assertSame('permission_denied', $response->getData()['error']['code']);
		}
	}

	public function testOfficeGroupMemberGetsOfficeRole(): void
	{
		$userManager = Server::get(IUserManager::class);
		$userManager->createUser(self::ALLOWED, self::PASSWORD);

		$groupManager = Server::get(\OCP\IGroupManager::class);
		$gid = 'iv_gate_office_group';
		$group = $groupManager->get($gid) ?? $groupManager->createGroup($gid);
		$this->assertNotNull($group);
		$group->addUser($userManager->get(self::ALLOWED));

		try {
			$config = Server::get(IConfig::class);
			$config->setAppValue(
				Application::APP_ID,
				AccessControlService::KEY_OFFICE_GROUP_IDS,
				json_encode([$gid], JSON_THROW_ON_ERROR),
			);
			$acl = Server::get(AccessControlService::class);
			$this->assertTrue($acl->isOffice(self::ALLOWED));
			$this->assertFalse($acl->isAppAdmin(self::ALLOWED));
		} finally {
			$groupManager->get($gid)?->delete();
		}
	}

	private function middlewareWithApiRequest(): AppAccessMiddleware
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn('/apps/inventorycheck/api/items');
		$request->method('getMethod')->willReturn('GET');

		return new AppAccessMiddleware(
			Server::get(IUserSession::class),
			Server::get(AccessControlService::class),
			$request,
			Server::get(\OCP\IURLGenerator::class),
			Server::get(\OCP\L10N\IFactory::class),
		);
	}

	private function middlewareWithPageRequest(): AppAccessMiddleware
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn('/apps/inventorycheck/');
		$request->method('getMethod')->willReturn('GET');

		return new AppAccessMiddleware(
			Server::get(IUserSession::class),
			Server::get(AccessControlService::class),
			$request,
			Server::get(\OCP\IURLGenerator::class),
			Server::get(\OCP\L10N\IFactory::class),
		);
	}
}
