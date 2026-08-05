<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Portfolio §2.1 — user-delete must scrub all live grants, seats, favourites, ACL. */
final class UserLifecyclePurgeContractTest extends TestCase
{
	public function testAccessControlPurgesEveryAuthorizationList(): void
	{
		$src = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/Service/AccessControlService.php');
		$this->assertStringContainsString('public function purgeUser(string $userId): void', $src);
		$this->assertStringContainsString('KEY_APP_ADMINS', $src);
		$this->assertStringContainsString('KEY_ACCESS_ALLOWED_USER_IDS', $src);
		$this->assertStringContainsString('KEY_OFFICE_USER_IDS', $src);
		$this->assertStringContainsString('LowStockNotifyService::KEY_NOTIFY_USER_IDS', $src);
	}

	public function testUserDeletedListenerScrubsSeatsFavouritesAndAcl(): void
	{
		$listener = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/Listener/UserDeletedListener.php');
		$app = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/AppInfo/Application.php');
		$this->assertStringContainsString('purgeUser', $listener);
		$this->assertStringContainsString('removeSeat', $listener);
		$this->assertStringContainsString('deleteAllForUser', $listener);
		$this->assertStringContainsString('locationAcl->purgeUser', $listener);
		$this->assertStringContainsString('UserDeletedListener', $app);
		$this->assertStringContainsString('UserDeletedEvent', $app);
		$this->assertStringContainsString('registerService(UserDeletedListener::class', $app);
	}

	public function testFavouriteMapperAndAclExposeUserPurge(): void
	{
		$fav = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/Db/LocationFavouriteMapper.php');
		$acl = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/Service/LocationAclService.php');
		$this->assertStringContainsString('public function deleteAllForUser(string $userId): void', $fav);
		$this->assertStringContainsString('public function purgeUser(string $userId): void', $acl);
		$this->assertStringContainsString("delete('iv_loc_acl')", $acl);
	}

	public function testOnlySystemAdminMayRewriteAppAdminList(): void
	{
		$ctrl = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/ConfigController.php');
		$this->assertStringContainsString(
			'array_key_exists(\'appAdmins\', $p) && $this->access->isSystemAdmin($uid)',
			$ctrl,
		);
		$this->assertStringNotContainsString(
			'array_key_exists(\'appAdmins\', $p) && $this->access->isAppAdmin($uid)',
			$ctrl,
		);
	}
}
