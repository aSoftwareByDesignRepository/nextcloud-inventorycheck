<?php

declare(strict_types=1);

namespace Test;

use PHPUnit\Framework\TestCase as PhpUnitTestCase;

class TestCase extends PhpUnitTestCase
{
	/** @var array<string, string>|null */
	private ?array $ivConfigSnapshot = null;

	/** @var array<string, true>|null */
	private ?array $ivPreExistingUids = null;

	protected function setUp(): void
	{
		parent::setUp();
		if (!self::ivHermetic()) {
			return;
		}
		$config = \OC::$server->get(\OCP\IConfig::class);
		$snap = [];
		foreach ($config->getAppKeys('inventorycheck') as $key) {
			$snap[$key] = $config->getAppValue('inventorycheck', $key);
		}
		$this->ivConfigSnapshot = $snap;

		// Hermetic: normalize toggleable gates to their app defaults so ambient
		// lab config cannot leak into assertions. Tests that need a non-default
		// value set it after parent::setUp(); tearDown restores the snapshot.
		foreach (['location_acl_enabled', 'location_acl_devices_strict'] as $gate) {
			if ($config->getAppValue('inventorycheck', $gate, '0') !== '0') {
				$config->setAppValue('inventorycheck', $gate, '0');
			}
		}

		$uids = [];
		foreach (\OC::$server->get(\OCP\IUserManager::class)->search('') as $user) {
			$uids[$user->getUID()] = true;
		}
		$this->ivPreExistingUids = $uids;
	}

	protected function tearDown(): void
	{
		try {
			if ($this->ivConfigSnapshot !== null && self::ivHermetic()) {
				$config = \OC::$server->get(\OCP\IConfig::class);
				foreach ($config->getAppKeys('inventorycheck') as $key) {
					if (!array_key_exists($key, $this->ivConfigSnapshot)) {
						$config->deleteAppValue('inventorycheck', $key);
					}
				}
				foreach ($this->ivConfigSnapshot as $key => $value) {
					if ($config->getAppValue('inventorycheck', $key) !== $value) {
						$config->setAppValue('inventorycheck', $key, $value);
					}
				}
				if ($this->ivPreExistingUids !== null) {
					$users = \OC::$server->get(\OCP\IUserManager::class);
					foreach ($users->search('') as $user) {
						$uid = $user->getUID();
						// Only this suite's own prefix — concurrent farm lanes create
						// users on the shared instance and must not be swept.
						if (!isset($this->ivPreExistingUids[$uid]) && str_starts_with($uid, 'iv')) {
							$user->delete();
						}
					}
				}
			}
		} finally {
			$this->ivConfigSnapshot = null;
			$this->ivPreExistingUids = null;
			parent::tearDown();
		}
	}

	private static function ivHermetic(): bool
	{
		// Only integration tests get live-instance snapshot/restore + fixture
		// cleanup; unit tests run without Nextcloud (or against mocks).
		return str_contains(static::class, '\\Tests\\Integration\\')
			&& class_exists(\OC::class)
			&& \OC::$server !== null;
	}
}
