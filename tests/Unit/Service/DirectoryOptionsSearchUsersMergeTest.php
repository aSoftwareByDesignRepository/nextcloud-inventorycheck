<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Service\DirectoryOptionsService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

final class DirectoryOptionsSearchUsersMergeTest extends TestCase
{
	private function user(string $uid, string $display): IUser
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($display);
		return $user;
	}

	public function testMergesDisplayNameHitsAndEnforcesMinLength(): void
	{
		$users = $this->createMock(IUserManager::class);
		$users->expects($this->never())->method('search');
		$svc = new DirectoryOptionsService($users, $this->createMock(IGroupManager::class));
		self::assertSame([], $svc->searchUsers('a'));

		$alice = $this->user('alice', 'Alice');
		$bob = $this->user('bob', 'Bob');
		$users2 = $this->createMock(IUserManager::class);
		$users2->method('search')->with('al', 20, 0)->willReturn([$alice]);
		$users2->method('searchDisplayName')->with('al', 20, 0)->willReturn([$bob, $alice]);
		$out = (new DirectoryOptionsService($users2, $this->createMock(IGroupManager::class)))->searchUsers('al', 20);
		self::assertSame(['alice', 'bob'], array_column($out, 'id'));
	}
}
