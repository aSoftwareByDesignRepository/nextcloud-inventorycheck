<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Db\Item;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\Lot;
use OCA\InventoryCheck\Db\LotMapper;
use OCA\InventoryCheck\Db\MovementMapper;
use OCA\InventoryCheck\Db\NotifyLogMapper;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\Clock;
use OCA\InventoryCheck\Service\LotExpiryNotifyService;
use OCA\InventoryCheck\Service\RestockConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * IV-LOT-EXPIRY-PACK (MH-04): per lot+recipient+day dedupe, office-only
 * recipients (NN-02), zero-qty lots never notify (NN-04), watch-off no-op.
 */
final class LotExpiryNotifyServiceTest extends TestCase
{
	private const NOW = 1_800_000_000;

	private function lot(int $id, string $code, ?int $expiresAt, int $itemId = 7): Lot
	{
		$lot = new Lot();
		$lot->setId($id);
		$lot->setItemId($itemId);
		$lot->setLotCode($code);
		$lot->setExpiresAt($expiresAt);
		return $lot;
	}

	private function makeService(
		LotMapper $lots,
		MovementMapper $movements,
		NotifyLogMapper $log,
		array $officeUids,
		INotificationManager $notifications,
		int $warnDays = 30,
	): LotExpiryNotifyService {
		$items = $this->createMock(ItemMapper::class);
		$item = new Item();
		$item->setId(7);
		$item->setName('Thing');
		$item->setSku('SKU7');
		$items->method('findById')->willReturn($item);
		$access = $this->createMock(AccessControlService::class);
		$access->method('getJsonIdList')->willReturnCallback(
			static fn (string $key): array => $key === AccessControlService::KEY_OFFICE_USER_IDS ? $officeUids : [],
		);
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturn(true);
		$cfg = $this->createMock(\OCP\IConfig::class);
		$cfg->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => $key === RestockConfig::KEY_EXPIRY_WARN_DAYS ? (string)$warnDays : $default,
		);
		$restock = new RestockConfig($cfg);
		$clock = $this->createMock(Clock::class);
		$clock->method('now')->willReturn(self::NOW);
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('https://cloud.example/item');
		return new LotExpiryNotifyService(
			$lots,
			$movements,
			$items,
			$log,
			$access,
			$users,
			$notifications,
			$url,
			$restock,
			$clock,
			new NullLogger(),
		);
	}

	public function testNotifiesEachOfficeRecipientOncePerLot(): void
	{
		$lots = $this->createMock(LotMapper::class);
		$lots->method('expiringWithin')->willReturn([$this->lot(5, 'L1', self::NOW + 86400)]);
		$movements = $this->createMock(MovementMapper::class);
		$movements->method('sumQtyDeltaByItemLotMap')->willReturn([7 => ['L1' => 3]]);
		$log = $this->createMock(NotifyLogMapper::class);
		$log->method('tryInsert')->willReturn(true);
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject', 'setLink'] as $m) {
			$notification->method($m)->willReturnSelf();
		}
		$notifications = $this->createMock(INotificationManager::class);
		$notifications->method('createNotification')->willReturn($notification);
		$notifications->expects(self::exactly(2))->method('notify');

		$this->makeService($lots, $movements, $log, ['alice', 'bob'], $notifications)->run();
	}

	public function testDedupeSkipsSecondRunSameDay(): void
	{
		$lots = $this->createMock(LotMapper::class);
		$lots->method('expiringWithin')->willReturn([$this->lot(5, 'L1', self::NOW + 86400)]);
		$movements = $this->createMock(MovementMapper::class);
		$movements->method('sumQtyDeltaByItemLotMap')->willReturn([7 => ['L1' => 3]]);
		$log = $this->createMock(NotifyLogMapper::class);
		$log->method('tryInsert')->willReturn(false); // already logged today
		$notifications = $this->createMock(INotificationManager::class);
		$notifications->expects(self::never())->method('notify');

		$this->makeService($lots, $movements, $log, ['alice'], $notifications)->run();
	}

	public function testZeroQtyLotsNeverNotify(): void
	{
		$lots = $this->createMock(LotMapper::class);
		$lots->method('expiringWithin')->willReturn([$this->lot(5, 'L1', self::NOW + 86400)]);
		$movements = $this->createMock(MovementMapper::class);
		$movements->method('sumQtyDeltaByItemLotMap')->willReturn([7 => ['L1' => 0]]);
		$log = $this->createMock(NotifyLogMapper::class);
		$log->expects(self::never())->method('tryInsert');
		$notifications = $this->createMock(INotificationManager::class);
		$notifications->expects(self::never())->method('notify');

		$this->makeService($lots, $movements, $log, ['alice'], $notifications)->run();
	}

	public function testWatchOffIsNoOp(): void
	{
		$lots = $this->createMock(LotMapper::class);
		$lots->expects(self::never())->method('expiringWithin');
		$notifications = $this->createMock(INotificationManager::class);
		$notifications->expects(self::never())->method('notify');

		$this->makeService(
			$lots,
			$this->createMock(MovementMapper::class),
			$this->createMock(NotifyLogMapper::class),
			['alice'],
			$notifications,
			0,
		)->run();
	}

	public function testNoOfficeRecipientsNoNotify(): void
	{
		$lots = $this->createMock(LotMapper::class);
		$lots->method('expiringWithin')->willReturn([$this->lot(5, 'L1', self::NOW + 86400)]);
		$movements = $this->createMock(MovementMapper::class);
		$movements->method('sumQtyDeltaByItemLotMap')->willReturn([7 => ['L1' => 3]]);
		$notifications = $this->createMock(INotificationManager::class);
		$notifications->expects(self::never())->method('notify');

		$this->makeService($lots, $movements, $this->createMock(NotifyLogMapper::class), [], $notifications)->run();
	}
}
