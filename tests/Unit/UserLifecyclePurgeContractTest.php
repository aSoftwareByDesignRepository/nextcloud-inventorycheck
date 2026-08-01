<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Portfolio §2.1 — user-delete must scrub app_admin_user_ids / allow lists. */
final class UserLifecyclePurgeContractTest extends TestCase
{
	public function testAccessControlExposesPurgeUser(): void
	{
		$src = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/Service/AccessControlService.php');
		$this->assertStringContainsString('public function purgeUser(string $userId): void', $src);
		$this->assertStringContainsString('KEY_APP_ADMINS', $src);
		$this->assertStringContainsString('KEY_ACCESS_ALLOWED_USER_IDS', $src);
	}

	public function testUserDeletedListenerRegisteredAndCallsPurge(): void
	{
		$listener = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/Listener/UserDeletedListener.php');
		$app = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/AppInfo/Application.php');
		$this->assertStringContainsString('purgeUser', $listener);
		$this->assertStringContainsString('UserDeletedListener', $app);
		$this->assertStringContainsString('UserDeletedEvent', $app);
	}

	public function testDedicatedAppAdminsMayRewriteAppAdminList(): void
	{
		$ctrl = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/ConfigController.php');
		$this->assertStringContainsString('isAppAdmin($uid)', $ctrl);
		$this->assertStringContainsString('cannot_remove_self', $ctrl);
		$this->assertStringContainsString('array_key_exists(\'appAdmins\', $p) && $this->access->isAppAdmin($uid)', $ctrl);
	}
}
