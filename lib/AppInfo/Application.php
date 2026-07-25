<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\AppInfo;

use OCA\InventoryCheck\Command\RebuildBalancesCommand;
use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\LicenseStateMapper;
use OCA\InventoryCheck\Db\LocationMapper;
use OCA\InventoryCheck\Db\MobileSeatMapper;
use OCA\InventoryCheck\Db\MovementMapper;
use OCA\InventoryCheck\Db\ScanDeviceMapper;
use OCA\InventoryCheck\Middleware\AppAccessMiddleware;
use OCA\InventoryCheck\Repair\BackupBeforeUpdate;
use OCA\InventoryCheck\Repair\EnsureInventoryCheckSchema;
use OCA\InventoryCheck\Repair\UninstallDropTables;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\BalanceService;
use OCA\InventoryCheck\Service\Clock;
use OCA\InventoryCheck\Service\DevicePairingService;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\LicenseService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\LowStockService;
use OCA\InventoryCheck\Service\MobileGateService;
use OCA\InventoryCheck\Service\MovementService;
use OCA\InventoryCheck\Service\UpgradeBackupService;
use OCP\App\IAppManager;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Lock\ILockingProvider;

class Application extends App implements IBootstrap
{
	public const APP_ID = 'inventorycheck';

	public function __construct(array $urlParams = [])
	{
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void
	{
		$context->registerService(LocationMapper::class, static fn ($c) => new LocationMapper($c->get(IDBConnection::class)));
		$context->registerService(ItemMapper::class, static fn ($c) => new ItemMapper($c->get(IDBConnection::class)));
		$context->registerService(BalanceMapper::class, static fn ($c) => new BalanceMapper($c->get(IDBConnection::class)));
		$context->registerService(MovementMapper::class, static fn ($c) => new MovementMapper($c->get(IDBConnection::class)));
		$context->registerService(LicenseStateMapper::class, static fn ($c) => new LicenseStateMapper($c->get(IDBConnection::class)));
		$context->registerService(MobileSeatMapper::class, static fn ($c) => new MobileSeatMapper($c->get(IDBConnection::class)));
		$context->registerService(ScanDeviceMapper::class, static fn ($c) => new ScanDeviceMapper($c->get(IDBConnection::class)));

		$context->registerService(Clock::class, static fn () => new Clock());

		$context->registerService(AccessControlService::class, static function ($c): AccessControlService {
			return new AccessControlService(
				$c->get(IConfig::class),
				$c->get(IGroupManager::class),
				$c->get(IUserSession::class),
			);
		});
		$context->registerService(LocationService::class, static function ($c): LocationService {
			return new LocationService(
				$c->get(IDBConnection::class),
				$c->get(LocationMapper::class),
				$c->get(AccessControlService::class),
				$c->get(Clock::class),
			);
		});
		$context->registerService(ItemService::class, static function ($c): ItemService {
			return new ItemService(
				$c->get(IDBConnection::class),
				$c->get(ItemMapper::class),
				$c->get(BalanceMapper::class),
				$c->get(AccessControlService::class),
				$c->get(Clock::class),
				$c->get(ILockingProvider::class),
			);
		});
		$context->registerService(BalanceService::class, static function ($c): BalanceService {
			return new BalanceService($c->get(BalanceMapper::class));
		});
		$context->registerService(MovementService::class, static function ($c): MovementService {
			return new MovementService(
				$c->get(IDBConnection::class),
				$c->get(MovementMapper::class),
				$c->get(BalanceMapper::class),
				$c->get(ItemMapper::class),
				$c->get(LocationMapper::class),
				$c->get(AccessControlService::class),
				$c->get(Clock::class),
			);
		});
		$context->registerService(LowStockService::class, static function ($c): LowStockService {
			return new LowStockService($c->get(ItemMapper::class), $c->get(BalanceMapper::class));
		});
		$context->registerService(LicenseService::class, static function ($c): LicenseService {
			return new LicenseService(
				$c->get(IDBConnection::class),
				$c->get(LicenseStateMapper::class),
				$c->get(MobileSeatMapper::class),
				$c->get(ScanDeviceMapper::class),
				$c->get(Clock::class),
				$c->get(IUserManager::class),
				$c->get(ILockingProvider::class),
				$c->get(IConfig::class),
			);
		});
		$context->registerService(DevicePairingService::class, static function ($c): DevicePairingService {
			return new DevicePairingService(
				$c->get(LicenseService::class),
				$c->get(ScanDeviceMapper::class),
				$c->get(Clock::class),
				$c->get(IConfig::class),
				$c->get(ILockingProvider::class),
			);
		});
		$context->registerService(MobileGateService::class, static function ($c): MobileGateService {
			return new MobileGateService(
				$c->get(LicenseService::class),
				$c->get(AccessControlService::class),
				$c->get(Clock::class),
			);
		});

		$context->registerService(EnsureInventoryCheckSchema::class, static function ($c): EnsureInventoryCheckSchema {
			return new EnsureInventoryCheckSchema($c->get(IDBConnection::class), $c->get(IConfig::class));
		});
		$context->registerService(UninstallDropTables::class, static function ($c): UninstallDropTables {
			return new UninstallDropTables(
				$c->get(IDBConnection::class),
				$c->get(IConfig::class),
				$c->get(IRootFolder::class),
			);
		});
		$context->registerService(UpgradeBackupService::class, function ($c): UpgradeBackupService {
			return new UpgradeBackupService(
				$c->get(IDBConnection::class),
				$c->get(IConfig::class),
				$c->get(IRootFolder::class),
				$c->get(IAppManager::class),
				$c->get(ILockingProvider::class),
				$c->get(\Psr\Log\LoggerInterface::class),
			);
		});
		$context->registerService(BackupBeforeUpdate::class, function ($c): BackupBeforeUpdate {
			return new BackupBeforeUpdate(
				$c->get(UpgradeBackupService::class),
			);
		});
		$context->registerService(RebuildBalancesCommand::class, static function ($c): RebuildBalancesCommand {
			return new RebuildBalancesCommand(
				$c->get(IDBConnection::class),
				$c->get(IConfig::class),
				$c->get(MovementMapper::class),
				$c->get(BalanceMapper::class),
			);
		});

		$context->registerService(AppAccessMiddleware::class, static function ($c): AppAccessMiddleware {
			return new AppAccessMiddleware(
				$c->get(IUserSession::class),
				$c->get(AccessControlService::class),
				$c->get(IRequest::class),
				$c->get(IURLGenerator::class),
				$c->get(IFactory::class),
			);
		});
		$context->registerMiddleware(AppAccessMiddleware::class);
	}

	public function boot(IBootContext $context): void
	{
	}
}
