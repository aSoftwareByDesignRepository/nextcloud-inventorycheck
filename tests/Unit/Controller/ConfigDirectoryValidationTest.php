<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Controller;

use OCA\InventoryCheck\Controller\ConfigController;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Config list writes must reject unknown uids/gids (no silent lock-out typos).
 */
final class ConfigDirectoryValidationTest extends TestCase
{
	/** @var IRequest&MockObject */
	private IRequest $request;
	/** @var AccessControlService&MockObject */
	private AccessControlService $access;
	/** @var IUserManager&MockObject */
	private IUserManager $users;
	/** @var IGroupManager&MockObject */
	private IGroupManager $groups;

	private ConfigController $controller;

	protected function setUp(): void
	{
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->access = $this->createMock(AccessControlService::class);
		$this->users = $this->createMock(IUserManager::class);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->controller = new ConfigController(
			$this->request,
			$this->access,
			$this->users,
			$this->groups,
		);
	}

	public function testUnknownAllowedUserRejected(): void
	{
		$this->access->method('currentUserId')->willReturn('admin');
		$this->access->expects($this->once())->method('requireAppAdmin')->with('admin');
		$this->users->method('userExists')->willReturnMap([
			['alice', true],
			['no-such-user', false],
		]);
		$this->request->method('getParams')->willReturn([
			'allowedUsers' => ['alice', 'no-such-user'],
		]);
		$this->access->expects($this->never())->method('setJsonIdList');

		try {
			$this->controller->saveAccess();
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('unknown_user', $e->getErrorCode());
			$this->assertSame([['field' => 'allowedUsers', 'code' => 'unknown_user']], $e->getDetails());
		}
	}

	public function testUnknownAllowedGroupRejected(): void
	{
		$this->access->method('currentUserId')->willReturn('admin');
		$this->access->expects($this->once())->method('requireAppAdmin');
		$this->groups->method('groupExists')->willReturn(false);
		$this->request->method('getParams')->willReturn([
			'allowedGroups' => ['ghost-group'],
		]);
		$this->access->expects($this->never())->method('setJsonIdList');

		try {
			$this->controller->saveAccess();
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('unknown_group', $e->getErrorCode());
			$this->assertSame([['field' => 'allowedGroups', 'code' => 'unknown_group']], $e->getDetails());
		}
	}

	public function testUnknownOfficeUserRejected(): void
	{
		$this->access->method('currentUserId')->willReturn('admin');
		$this->access->expects($this->once())->method('requireAppAdmin');
		$this->users->method('userExists')->willReturn(false);
		$this->request->method('getParams')->willReturn([
			'officeUsers' => ['missing'],
		]);
		$this->access->expects($this->never())->method('setJsonIdList');

		try {
			$this->controller->saveOffice();
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('unknown_user', $e->getErrorCode());
			$this->assertSame('officeUsers', $e->getDetails()[0]['field']);
		}
	}

	public function testUnknownOfficeGroupRejected(): void
	{
		$this->access->method('currentUserId')->willReturn('admin');
		$this->access->expects($this->once())->method('requireAppAdmin');
		$this->groups->method('groupExists')->willReturn(false);
		$this->request->method('getParams')->willReturn([
			'officeGroups' => ['nope'],
		]);

		try {
			$this->controller->saveOffice();
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('unknown_group', $e->getErrorCode());
		}
	}

	public function testSystemAdminAppAdminsValidatedAndSaved(): void
	{
		$this->access->method('currentUserId')->willReturn('root');
		$this->access->expects($this->once())->method('requireAppAdmin')->with('root');
		$this->access->method('isSystemAdmin')->with('root')->willReturn(true);
		$this->users->method('userExists')->with('carol')->willReturn(true);
		$this->request->method('getParams')->willReturn([
			'appAdmins' => [' carol ', '', 'carol'],
		]);
		$this->access->expects($this->once())->method('setJsonIdList')->with(
			AccessControlService::KEY_APP_ADMINS,
			['carol'],
		);
		$this->access->method('isAppAdmin')->willReturn(true);
		$this->access->method('isOffice')->willReturn(true);
		$this->access->method('allowNegativeStock')->willReturn(false);
		$this->access->method('isAccessRestrictionEnabled')->willReturn(false);
		$this->access->method('getJsonIdList')->willReturn([]);

		$response = $this->controller->saveAccess();
		$this->assertSame(200, $response->getStatus());
	}

	public function testAppAdminCannotRewriteAppAdminsList(): void
	{
		$this->access->method('currentUserId')->willReturn('carol');
		$this->access->expects($this->once())->method('requireAppAdmin')->with('carol');
		$this->access->method('isSystemAdmin')->with('carol')->willReturn(false);
		$this->request->method('getParams')->willReturn([
			'appAdmins' => ['attacker'],
			'allowedUsers' => [],
		]);
		// allowedUsers empty list is validated+saved; appAdmins must NOT be written.
		$this->access->expects($this->once())->method('setJsonIdList')->with(
			AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS,
			[],
		);
		$this->access->method('isAppAdmin')->willReturn(true);
		$this->access->method('isOffice')->willReturn(true);
		$this->access->method('allowNegativeStock')->willReturn(false);
		$this->access->method('isAccessRestrictionEnabled')->willReturn(false);
		$this->access->method('getJsonIdList')->willReturn([]);

		$this->controller->saveAccess();
	}

	public function testNonArrayAllowedUsersRejected(): void
	{
		$this->access->method('currentUserId')->willReturn('admin');
		$this->access->expects($this->once())->method('requireAppAdmin');
		$this->request->method('getParams')->willReturn([
			'allowedUsers' => 'alice',
		]);

		try {
			$this->controller->saveAccess();
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('validation_failed', $e->getErrorCode());
			$this->assertSame('invalid_type', $e->getDetails()[0]['code']);
		}
	}

	public function testEmptyListsAllowed(): void
	{
		$this->access->method('currentUserId')->willReturn('admin');
		$this->access->expects($this->once())->method('requireAppAdmin');
		$this->request->method('getParams')->willReturn([
			'allowedUsers' => [],
			'allowedGroups' => [],
		]);
		$calls = [];
		$this->access->expects($this->exactly(2))->method('setJsonIdList')
			->willReturnCallback(function (string $key, array $ids) use (&$calls): void {
				$calls[] = [$key, $ids];
			});
		$this->access->method('isAppAdmin')->willReturn(true);
		$this->access->method('isSystemAdmin')->willReturn(true);
		$this->access->method('isOffice')->willReturn(true);
		$this->access->method('allowNegativeStock')->willReturn(false);
		$this->access->method('isAccessRestrictionEnabled')->willReturn(false);
		$this->access->method('getJsonIdList')->willReturn([]);

		$this->controller->saveAccess();
		$this->assertSame([
			[AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS, []],
			[AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS, []],
		], $calls);
	}

	public function testValidUsersAndGroupsSaved(): void
	{
		$this->access->method('currentUserId')->willReturn('admin');
		$this->access->expects($this->once())->method('requireAppAdmin');
		$this->users->method('userExists')->with('alice')->willReturn(true);
		$this->groups->method('groupExists')->with('office')->willReturn(true);
		$this->request->method('getParams')->willReturn([
			'allowedUsers' => ['alice'],
			'allowedGroups' => ['office'],
		]);
		$keys = [];
		$this->access->expects($this->exactly(2))->method('setJsonIdList')
			->willReturnCallback(function (string $key, array $ids) use (&$keys): void {
				$keys[$key] = $ids;
			});
		$this->access->method('isAppAdmin')->willReturn(true);
		$this->access->method('isSystemAdmin')->willReturn(false);
		$this->access->method('isOffice')->willReturn(true);
		$this->access->method('allowNegativeStock')->willReturn(false);
		$this->access->method('isAccessRestrictionEnabled')->willReturn(false);
		$this->access->method('getJsonIdList')->willReturn([]);

		$this->controller->saveAccess();
		$this->assertSame(['alice'], $keys[AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS]);
		$this->assertSame(['office'], $keys[AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS]);
	}

	public function testInvalidGroupDoesNotWriteValidUsers(): void
	{
		$this->access->method('currentUserId')->willReturn('admin');
		$this->access->expects($this->once())->method('requireAppAdmin');
		$this->users->method('userExists')->with('alice')->willReturn(true);
		$this->groups->method('groupExists')->with('ghost')->willReturn(false);
		$this->request->method('getParams')->willReturn([
			'allowedUsers' => ['alice'],
			'allowedGroups' => ['ghost'],
		]);
		$this->access->expects($this->never())->method('setJsonIdList');
		$this->access->expects($this->never())->method('setAccessRestrictionEnabled');

		try {
			$this->controller->saveAccess();
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('unknown_group', $e->getErrorCode());
		}
	}

	public function testStringZeroDisablesRestriction(): void
	{
		$this->access->method('currentUserId')->willReturn('admin');
		$this->access->expects($this->once())->method('requireAppAdmin');
		$this->request->method('getParams')->willReturn([
			'accessRestrictionEnabled' => '0',
		]);
		$this->access->expects($this->once())->method('setAccessRestrictionEnabled')->with(false);
		$this->access->method('isAppAdmin')->willReturn(true);
		$this->access->method('isSystemAdmin')->willReturn(true);
		$this->access->method('isOffice')->willReturn(true);
		$this->access->method('allowNegativeStock')->willReturn(false);
		$this->access->method('isAccessRestrictionEnabled')->willReturn(false);
		$this->access->method('getJsonIdList')->willReturn([]);

		$response = $this->controller->saveAccess();
		$this->assertSame(200, $response->getStatus());
	}

	public function testStringOneEnablesRestriction(): void
	{
		$this->access->method('currentUserId')->willReturn('admin');
		$this->access->expects($this->once())->method('requireAppAdmin');
		$this->request->method('getParams')->willReturn([
			'accessRestrictionEnabled' => '1',
		]);
		$this->access->expects($this->once())->method('setAccessRestrictionEnabled')->with(true);
		$this->access->method('isAppAdmin')->willReturn(true);
		$this->access->method('isSystemAdmin')->willReturn(true);
		$this->access->method('isOffice')->willReturn(true);
		$this->access->method('allowNegativeStock')->willReturn(false);
		$this->access->method('isAccessRestrictionEnabled')->willReturn(true);
		$this->access->method('getJsonIdList')->willReturn([]);

		$this->controller->saveAccess();
	}

	public function testInvalidBoolRejected(): void
	{
		$this->access->method('currentUserId')->willReturn('admin');
		$this->access->expects($this->once())->method('requireAppAdmin');
		$this->request->method('getParams')->willReturn([
			'accessRestrictionEnabled' => 'yes',
		]);
		$this->access->expects($this->never())->method('setAccessRestrictionEnabled');

		try {
			$this->controller->saveAccess();
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('validation_failed', $e->getErrorCode());
			$this->assertSame('accessRestrictionEnabled', $e->getDetails()[0]['field']);
		}
	}

	public function testOfficeInvalidGroupDoesNotWriteUsersOrNegative(): void
	{
		$this->access->method('currentUserId')->willReturn('admin');
		$this->access->expects($this->once())->method('requireAppAdmin');
		$this->users->method('userExists')->with('alice')->willReturn(true);
		$this->groups->method('groupExists')->willReturn(false);
		$this->request->method('getParams')->willReturn([
			'officeUsers' => ['alice'],
			'officeGroups' => ['missing'],
			'allowNegativeStock' => false,
		]);
		$this->access->expects($this->never())->method('setJsonIdList');
		$this->access->expects($this->never())->method('setAllowNegativeStock');

		try {
			$this->controller->saveOffice();
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('unknown_group', $e->getErrorCode());
		}
	}
}
