<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller {
	/**
	 * Test-only stubs so get_class() retains the Controller namespace prefix
	 * that AppAccessMiddleware requires (PHPUnit mocks do not).
	 */
	final class EnvelopeTestItemController
	{
	}

	final class EnvelopeTestMobileController
	{
	}
}

namespace OCA\InventoryCheck\Tests\Unit\Middleware {

use OCA\InventoryCheck\Controller\EnvelopeTestItemController;
use OCA\InventoryCheck\Controller\EnvelopeTestMobileController;
use OCA\InventoryCheck\Exception\AppAccessDeniedException;
use OCA\InventoryCheck\Exception\ConflictException;
use OCA\InventoryCheck\Exception\InsufficientStockException;
use OCA\InventoryCheck\Exception\MobileGateException;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\PermissionDeniedException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Middleware\AppAccessMiddleware;
use OCA\InventoryCheck\Service\AccessControlService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * SPEC §7.1 error envelope + AC-2 page vs JSON access denial.
 * Pure unit — no DB.
 */
final class AppAccessMiddlewareEnvelopeTest extends TestCase
{
	/** @var IUserSession&MockObject */
	private IUserSession $userSession;
	/** @var AccessControlService&MockObject */
	private AccessControlService $access;
	/** @var IRequest&MockObject */
	private IRequest $request;
	private AppAccessMiddleware $middleware;
	private EnvelopeTestItemController $itemController;
	private EnvelopeTestMobileController $mobileController;

	protected function setUp(): void
	{
		parent::setUp();
		$this->userSession = $this->createMock(IUserSession::class);
		$this->access = $this->createMock(AccessControlService::class);
		$this->request = $this->createMock(IRequest::class);

		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToDefaultPageUrl')->willReturn('/index.php/apps/files');

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static function (string $text, $params = []): string {
			if (is_array($params) && $params !== []) {
				return $text . '|' . implode(',', array_map('strval', $params));
			}
			return $text;
		});
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l);

		$this->middleware = new AppAccessMiddleware(
			$this->userSession,
			$this->access,
			$this->request,
			$url,
			$factory,
		);

		$this->itemController = new EnvelopeTestItemController();
		$this->mobileController = new EnvelopeTestMobileController();
	}

	private function apiPath(): void
	{
		$this->request->method('getPathInfo')->willReturn('/apps/inventorycheck/api/items');
		$this->request->method('getMethod')->willReturn('GET');
	}

	private function pagePath(): void
	{
		$this->request->method('getPathInfo')->willReturn('/apps/inventorycheck/');
		$this->request->method('getMethod')->willReturn('GET');
	}

	private function sessionUser(string $uid): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	public function testPairDeviceSkipsAccessGate(): void
	{
		$this->sessionUser('anyone');
		$this->access->expects($this->never())->method('canUseApp');
		$this->middleware->beforeController($this->mobileController, 'pairDevice');
		$this->middleware->beforeController($this->mobileController, 'scan');
		$this->addToAssertionCount(1);
	}

	public function testBeforeControllerPassesWhenCanUseApp(): void
	{
		$this->sessionUser('alice');
		$this->access->method('canUseApp')->with('alice')->willReturn(true);
		$this->middleware->beforeController($this->itemController, 'index');
		$this->addToAssertionCount(1);
	}

	public function testBeforeControllerThrowsWhenDenied(): void
	{
		$this->sessionUser('bob');
		$this->access->method('canUseApp')->with('bob')->willReturn(false);
		$this->access->method('denialReasonWhenCannotUseApp')->with('bob')
			->willReturn(AccessControlService::DENIAL_RESTRICTION);
		$this->expectException(AppAccessDeniedException::class);
		$this->middleware->beforeController($this->itemController, 'index');
	}

	public function testAccessDeniedApiReturnsJson403(): void
	{
		$this->apiPath();
		$response = $this->middleware->afterException(
			$this->itemController,
			'index',
			new AppAccessDeniedException(AccessControlService::DENIAL_RESTRICTION),
		);
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('app_access_denied', $response->getData()['error']['code']);
	}

	public function testAccessDeniedPageReturnsTemplate403(): void
	{
		$this->pagePath();
		$response = $this->middleware->afterException(
			$this->itemController,
			'dashboard',
			new AppAccessDeniedException(AccessControlService::DENIAL_RESTRICTION),
		);
		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testPostWithoutApiPathIsJsonRoute(): void
	{
		$this->request->method('getPathInfo')->willReturn('/apps/inventorycheck/items');
		$this->request->method('getMethod')->willReturn('POST');
		$response = $this->middleware->afterException(
			$this->itemController,
			'create',
			new AppAccessDeniedException(AccessControlService::DENIAL_RESTRICTION),
		);
		$this->assertInstanceOf(JSONResponse::class, $response);
	}

	public function testPermissionDeniedEnvelope(): void
	{
		$this->apiPath();
		$response = $this->middleware->afterException(
			$this->itemController,
			'receive',
			new PermissionDeniedException(),
		);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('permission_denied', $response->getData()['error']['code']);
	}

	public function testNotFoundCodeNotFound(): void
	{
		$this->apiPath();
		$response = $this->middleware->afterException(
			$this->itemController,
			'byCode',
			new NotFoundException('code_not_found'),
		);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('code_not_found', $response->getData()['error']['code']);
	}

	public function testInsufficientStockConflict(): void
	{
		$this->apiPath();
		$response = $this->middleware->afterException(
			$this->itemController,
			'issue',
			new InsufficientStockException(3, 'VAN-1'),
		);
		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('insufficient_stock', $response->getData()['error']['code']);
		$this->assertSame(
			'Only %s left in %s.|3,VAN-1',
			$response->getData()['error']['message'],
			'UJ-3 toast must name available qty and location (not plural %n misuse)',
		);
	}

	public function testInsufficientStockWithoutLocationLabel(): void
	{
		$this->apiPath();
		$response = $this->middleware->afterException(
			$this->itemController,
			'issue',
			new InsufficientStockException(7),
		);
		$this->assertSame(
			'Not enough stock at this location (available: %s).|7',
			$response->getData()['error']['message'],
		);
	}

	public function testUnknownItemNotFound(): void
	{
		$this->apiPath();
		$response = $this->middleware->afterException(
			$this->itemController,
			'show',
			new NotFoundException('unknown_item'),
		);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('unknown_item', $response->getData()['error']['code']);
	}

	public function testUnknownLocationNotFound(): void
	{
		$this->apiPath();
		$response = $this->middleware->afterException(
			$this->itemController,
			'show',
			new NotFoundException('unknown_location'),
		);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('unknown_location', $response->getData()['error']['code']);
	}

	public function testConflictCodeExists(): void
	{
		$this->apiPath();
		$response = $this->middleware->afterException(
			$this->itemController,
			'create',
			new ConflictException('code_exists'),
		);
		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('code_exists', $response->getData()['error']['code']);
	}

	public function testValidationWithDetails(): void
	{
		$this->apiPath();
		$response = $this->middleware->afterException(
			$this->itemController,
			'receive',
			new ValidationException('invalid_qty', '', [['field' => 'qty', 'code' => 'invalid_qty']]),
		);
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('invalid_qty', $data['error']['code']);
		$this->assertSame([['field' => 'qty', 'code' => 'invalid_qty']], $data['error']['details']);
	}

	public function testMobileGatePaymentRequired(): void
	{
		$this->request->method('getPathInfo')->willReturn('/apps/inventorycheck/mobile/bootstrap');
		$this->request->method('getMethod')->willReturn('GET');
		$response = $this->middleware->afterException(
			$this->mobileController,
			'bootstrap',
			new MobileGateException('seat_required'),
		);
		$this->assertSame(402, $response->getStatus());
		$this->assertSame('seat_required', $response->getData()['error']['code']);
	}

	public function testMobileGateAuthRequiredUnauthorized(): void
	{
		$this->request->method('getPathInfo')->willReturn('/apps/inventorycheck/mobile/v1/bootstrap');
		$this->request->method('getMethod')->willReturn('GET');
		$response = $this->middleware->afterException(
			$this->mobileController,
			'bootstrap',
			new MobileGateException('auth_required'),
		);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('auth_required', $response->getData()['error']['code']);
	}

	public function testMobileGateRateLimited(): void
	{
		$this->request->method('getPathInfo')->willReturn('/apps/inventorycheck/mobile/pair');
		$this->request->method('getMethod')->willReturn('POST');
		$response = $this->middleware->afterException(
			$this->mobileController,
			'pairDevice',
			new MobileGateException('rate_limited'),
		);
		$this->assertSame(429, $response->getStatus());
		$this->assertSame('rate_limited', $response->getData()['error']['code']);
	}

	public function testUnknownExceptionRethrown(): void
	{
		$this->apiPath();
		$this->expectException(\RuntimeException::class);
		$this->middleware->afterException(
			$this->itemController,
			'index',
			new \RuntimeException('boom'),
		);
	}
}

}
