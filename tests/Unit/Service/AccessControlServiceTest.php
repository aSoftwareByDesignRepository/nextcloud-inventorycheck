<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Exception\PermissionDeniedException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * L0–L3 truth table (IMPLEMENTATION §2). Config and group membership are
 * faked in-memory; every layer is probed positively and negatively.
 */
final class AccessControlServiceTest extends TestCase
{
	/** @var array<string, string> */
	private array $appValues = [];

	/** @var array<string, list<string>> user → groups */
	private array $groups = [];

	/** @var list<string> */
	private array $systemAdmins = [];

	private ?string $sessionUid = null;

	private AccessControlService $acl;

	protected function setUp(): void
	{
		$this->appValues = [];
		$this->groups = [];
		$this->systemAdmins = [];
		$this->sessionUid = null;

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $app === Application::APP_ID
				? ($this->appValues[$key] ?? $default)
				: $default,
		);
		$config->method('setAppValue')->willReturnCallback(
			function (string $app, string $key, string $value): void {
				$this->assertSame(Application::APP_ID, $app);
				$this->appValues[$key] = $value;
			},
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(
			fn (string $uid): bool => in_array($uid, $this->systemAdmins, true),
		);
		$groupManager->method('isInGroup')->willReturnCallback(
			fn (string $uid, string $gid): bool => in_array($gid, $this->groups[$uid] ?? [], true),
		);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturnCallback(function (): ?IUser {
			if ($this->sessionUid === null) {
				return null;
			}
			/** @var IUser&MockObject $user */
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($this->sessionUid);
			return $user;
		});

		$this->acl = new AccessControlService($config, $groupManager, $userSession);
	}

	/**
	 * @param list<string> $ids
	 */
	private function setList(string $key, array $ids): void
	{
		$this->appValues[$key] = json_encode($ids, JSON_THROW_ON_ERROR);
	}

	// ── currentUserId ───────────────────────────────────────────────────

	public function testCurrentUserId(): void
	{
		$this->sessionUid = 'alice';
		$this->assertSame('alice', $this->acl->currentUserId());
	}

	public function testCurrentUserIdThrowsWithoutSession(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->acl->currentUserId();
	}

	// ── L0 / L1 ─────────────────────────────────────────────────────────

	public function testSystemAdminIsAlwaysAppAdmin(): void
	{
		$this->systemAdmins = ['root'];
		$this->assertTrue($this->acl->isSystemAdmin('root'));
		$this->assertTrue($this->acl->isAppAdmin('root'));
		$this->assertFalse($this->acl->isSystemAdmin('bob'));
		$this->assertFalse($this->acl->isAppAdmin('bob'));
	}

	public function testListedAppAdminWithoutSystemAdmin(): void
	{
		$this->setList(AccessControlService::KEY_APP_ADMINS, ['carol']);
		$this->assertTrue($this->acl->isAppAdmin('carol'));
		$this->assertFalse($this->acl->isSystemAdmin('carol'));
		$this->assertFalse($this->acl->isAppAdmin('dave'));
	}

	public function testEmptyUidNeverPasses(): void
	{
		$this->assertFalse($this->acl->isSystemAdmin(''));
		$this->assertFalse($this->acl->isAppAdmin(''));
		$this->assertFalse($this->acl->canUseApp(''));
		$this->assertFalse($this->acl->isOffice(''));
	}

	// ── L2 gate ─────────────────────────────────────────────────────────

	public function testEveryoneCanUseAppWhenUnrestricted(): void
	{
		$this->assertFalse($this->acl->isAccessRestrictionEnabled());
		$this->assertTrue($this->acl->canUseApp('anyone'));
	}

	/**
	 * Guards the exact config byte semantics. A !== flip would invert the
	 * whole L2 gate (default-open becomes default-locked) — mutation target.
	 */
	public function testRestrictionEnabledOnlyWhenConfigIsExactlyOne(): void
	{
		$this->assertArrayNotHasKey(AccessControlService::KEY_ACCESS_RESTRICTION, $this->appValues);
		$this->assertFalse($this->acl->isAccessRestrictionEnabled(), 'default missing key must be open');

		$this->appValues[AccessControlService::KEY_ACCESS_RESTRICTION] = '0';
		$this->assertFalse($this->acl->isAccessRestrictionEnabled());
		$this->assertTrue($this->acl->canUseApp('anyone'));

		$this->appValues[AccessControlService::KEY_ACCESS_RESTRICTION] = '1';
		$this->assertTrue($this->acl->isAccessRestrictionEnabled());
		$this->assertFalse($this->acl->canUseApp('outsider'));

		$this->appValues[AccessControlService::KEY_ACCESS_RESTRICTION] = 'true';
		$this->assertFalse($this->acl->isAccessRestrictionEnabled(), 'non-1 truthy strings must not enable');
	}

	public function testRestrictionBlocksUnlistedUsers(): void
	{
		$this->acl->setAccessRestrictionEnabled(true);
		$this->assertTrue($this->acl->isAccessRestrictionEnabled());
		$this->assertFalse($this->acl->canUseApp('outsider'));
	}

	public function testRestrictionAllowsListedUser(): void
	{
		$this->acl->setAccessRestrictionEnabled(true);
		$this->setList(AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS, ['erin']);
		$this->assertTrue($this->acl->canUseApp('erin'));
		$this->assertFalse($this->acl->canUseApp('outsider'));
	}

	public function testRestrictionAllowsListedGroupMember(): void
	{
		$this->acl->setAccessRestrictionEnabled(true);
		$this->setList(AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS, ['service-team']);
		$this->groups['frank'] = ['service-team'];
		$this->assertTrue($this->acl->canUseApp('frank'));
		$this->assertFalse($this->acl->canUseApp('grace'));
	}

	public function testAdminsBypassRestriction(): void
	{
		$this->acl->setAccessRestrictionEnabled(true);
		$this->systemAdmins = ['root'];
		$this->setList(AccessControlService::KEY_APP_ADMINS, ['carol']);
		$this->assertTrue($this->acl->canUseApp('root'));
		$this->assertTrue($this->acl->canUseApp('carol'));
	}

	public function testDenialReason(): void
	{
		$this->assertSame(AccessControlService::DENIAL_NOT_LOGGED_IN, $this->acl->denialReasonWhenCannotUseApp(''));
		$this->assertSame(AccessControlService::DENIAL_RESTRICTION, $this->acl->denialReasonWhenCannotUseApp('bob'));
	}

	// ── L3 office / technician ──────────────────────────────────────────

	public function testOfficeByUserList(): void
	{
		$this->setList(AccessControlService::KEY_OFFICE_USER_IDS, ['heidi']);
		$this->assertTrue($this->acl->isOffice('heidi'));
		$this->assertFalse($this->acl->isOffice('ivan'), 'unlisted user is technician');
	}

	public function testOfficeByGroup(): void
	{
		$this->setList(AccessControlService::KEY_OFFICE_GROUP_IDS, ['backoffice']);
		$this->groups['judy'] = ['backoffice', 'other'];
		$this->assertTrue($this->acl->isOffice('judy'));
		$this->assertFalse($this->acl->isOffice('mallory'));
	}

	public function testAppAdminIsAlwaysOffice(): void
	{
		$this->setList(AccessControlService::KEY_APP_ADMINS, ['carol']);
		$this->systemAdmins = ['root'];
		$this->assertTrue($this->acl->isOffice('carol'));
		$this->assertTrue($this->acl->isOffice('root'));
	}

	public function testRequireOffice(): void
	{
		$this->setList(AccessControlService::KEY_OFFICE_USER_IDS, ['heidi']);
		$this->acl->requireOffice('heidi');
		$this->addToAssertionCount(1);

		$this->expectException(PermissionDeniedException::class);
		$this->acl->requireOffice('ivan');
	}

	public function testRequireAppAdmin(): void
	{
		$this->setList(AccessControlService::KEY_APP_ADMINS, ['carol']);
		$this->acl->requireAppAdmin('carol');
		$this->addToAssertionCount(1);

		$this->expectException(PermissionDeniedException::class);
		$this->acl->requireAppAdmin('heidi');
	}

	public function testOfficeIsNotAppAdmin(): void
	{
		// L3 grants office rights only — settings stay admin-only (P7).
		$this->setList(AccessControlService::KEY_OFFICE_USER_IDS, ['heidi']);
		$this->assertFalse($this->acl->isAppAdmin('heidi'));
	}

	// ── List storage round-trip ─────────────────────────────────────────

	public function testJsonIdListRoundTripCleansInput(): void
	{
		$this->acl->setJsonIdList(AccessControlService::KEY_APP_ADMINS, [' carol ', '', 'carol', 'dave']);
		$this->assertSame(['carol', 'dave'], $this->acl->getJsonIdList(AccessControlService::KEY_APP_ADMINS));
	}

	public function testJsonIdListCleansRawStorageDuplicatesAndBlanks(): void
	{
		$this->appValues[AccessControlService::KEY_APP_ADMINS] = '["a", "a", "  b ", "", 7]';
		$this->assertSame(['a', 'b', '7'], $this->acl->getJsonIdList(AccessControlService::KEY_APP_ADMINS));
	}

	public function testJsonIdListToleratesCorruptStorage(): void
	{
		$this->appValues[AccessControlService::KEY_APP_ADMINS] = '{broken json';
		$this->assertSame([], $this->acl->getJsonIdList(AccessControlService::KEY_APP_ADMINS));

		$this->appValues[AccessControlService::KEY_APP_ADMINS] = '"a-string"';
		$this->assertSame([], $this->acl->getJsonIdList(AccessControlService::KEY_APP_ADMINS));
	}

	public function testRestrictionToggleRoundTrip(): void
	{
		$this->acl->setAccessRestrictionEnabled(true);
		$this->assertTrue($this->acl->isAccessRestrictionEnabled());
		$this->acl->setAccessRestrictionEnabled(false);
		$this->assertFalse($this->acl->isAccessRestrictionEnabled());
	}

	public function testAllowNegativeStockToggle(): void
	{
		$this->assertFalse($this->acl->allowNegativeStock());
		$this->acl->setAllowNegativeStock(true);
		$this->assertTrue($this->acl->allowNegativeStock());
		$this->acl->setAllowNegativeStock(false);
		$this->assertFalse($this->acl->allowNegativeStock());
	}
}
