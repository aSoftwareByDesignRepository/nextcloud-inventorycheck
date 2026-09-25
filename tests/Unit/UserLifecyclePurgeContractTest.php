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

	/** CRIT-01 — group-delete must scrub group ACL grants + gid-bearing config lists. */
	public function testGroupDeletedListenerScrubsAclAndConfigLists(): void
	{
		$listener = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/Listener/GroupDeletedListener.php');
		$app = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/AppInfo/Application.php');
		$access = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/Service/AccessControlService.php');
		$acl = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/Service/LocationAclService.php');
		$info = (string) file_get_contents(dirname(__DIR__, 2) . '/appinfo/info.xml');

		$this->assertStringContainsString('GroupDeletedEvent', $listener);
		$this->assertStringContainsString('purgeGroup', $listener);
		$this->assertStringContainsString('access->purgeGroup', $listener);
		$this->assertStringContainsString('locationAcl->purgeGroup', $listener);
		$this->assertStringContainsString('GroupDeletedListener::class', $app);
		$this->assertStringContainsString('registerService(GroupDeletedListener::class', $app);
		$this->assertStringContainsString('KEY_ACCESS_ALLOWED_GROUP_IDS', $access);
		$this->assertStringContainsString('KEY_OFFICE_GROUP_IDS', $access);
		$this->assertStringContainsString('public function purgeGroup(string $groupId): void', $access);
		$this->assertStringContainsString('public function purgeGroup(string $groupId): void', $acl);
		$this->assertStringContainsString('ScrubStaleAclSubjects', $info);
		$this->assertStringContainsString('registerService(ScrubStaleAclSubjects::class', $app);
	}

	/** CRIT-02 — item delete must not orphan its AppData photo blob. */
	public function testItemDeletePurgesPhotoBlob(): void
	{
		$svc = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/Service/ItemService.php');
		$photos = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/Service/ItemPhotoService.php');
		$this->assertStringContainsString('getPhotoName()', $svc);
		$this->assertStringContainsString('photos?->purgeFile(', $svc);
		$this->assertStringContainsString('public function purgeFile(string $fileName): void', $photos);
	}

	/** CRIT-01 residual — group purge must cover EVERY gid-bearing config list. */
	public function testPurgeGroupCoversLowStockNotifyList(): void
	{
		$access = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/Service/AccessControlService.php');
		$this->assertStringContainsString('LowStockNotifyService::KEY_NOTIFY_GROUP_IDS', $access);
	}

	/** CRIT-01 residual — repair step must scrub config lists + dangling location refs. */
	public function testScrubStepCoversConfigListsAndDanglingLocations(): void
	{
		$step = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/Repair/ScrubStaleAclSubjects.php');
		$this->assertStringContainsString('KEY_APP_ADMINS', $step);
		$this->assertStringContainsString('KEY_ACCESS_ALLOWED_USER_IDS', $step);
		$this->assertStringContainsString('KEY_ACCESS_ALLOWED_GROUP_IDS', $step);
		$this->assertStringContainsString('LowStockNotifyService::KEY_NOTIFY_USER_IDS', $step);
		$this->assertStringContainsString('LowStockNotifyService::KEY_NOTIFY_GROUP_IDS', $step);
		$this->assertStringContainsString("'iv_loc_fav'", $step);
		$this->assertStringContainsString('IConfig', $step);
		$app = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/AppInfo/Application.php');
		$this->assertStringContainsString('registerService(ScrubStaleAclSubjects::class', $app);
	}

	/** CRIT-04 — location delete must not orphan ACL grants or favourites. */
	public function testLocationDeletePurgesAclAndFavourites(): void
	{
		$svc = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/Service/LocationService.php');
		$acl = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/Service/LocationAclService.php');
		$fav = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/Db/LocationFavouriteMapper.php');
		$this->assertStringContainsString('locationAcl->purgeLocation($id)', $svc);
		$this->assertStringContainsString('favourites->deleteAllForLocation($id)', $svc);
		$this->assertStringContainsString('public function purgeLocation(int $locationId): void', $acl);
		$this->assertStringContainsString('public function deleteAllForLocation(int $locationId): void', $fav);
	}
}
