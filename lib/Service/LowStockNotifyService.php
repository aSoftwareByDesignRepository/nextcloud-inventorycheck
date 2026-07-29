<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Db\BalanceMapper;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\NotifyLogMapper;
use OCP\Activity\IManager as IActivityManager;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Wave A3: notify when an item *newly enters* low-stock.
 *
 * Episode model (not a sliding re-ping while still low):
 * - Open episode key `lowstock:open:{itemId}` marks “currently in low-stock”.
 * - Notifications fire only when that key is newly inserted (false→true).
 * - Recovery deletes the open key so a later dip can notify again.
 * - DEBOUNCE_SECONDS caps storms: ≤1 fire / item / 24h even across recoveries.
 */
class LowStockNotifyService
{
	public const KEY_NOTIFY_USER_IDS = 'low_stock_notify_user_ids';
	public const KEY_NOTIFY_GROUP_IDS = 'low_stock_notify_group_ids';
	public const DEBOUNCE_SECONDS = 86400;
	public const SUBJECT = 'low_stock';

	public function __construct(
		private readonly ItemMapper $items,
		private readonly BalanceMapper $balances,
		private readonly NotifyLogMapper $notifyLog,
		private readonly AccessControlService $access,
		private readonly Clock $clock,
		private readonly IConfig $config,
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
		private readonly INotificationManager $notificationManager,
		private readonly IActivityManager $activityManager,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
	) {
	}

	public static function openEpisodeKey(int $itemId): string
	{
		return 'lowstock:open:' . $itemId;
	}

	public function afterStockChange(int $itemId): void
	{
		$item = $this->items->findById($itemId);
		$sums = $this->balances->sumQtyByItem();
		$total = $sums[$itemId] ?? 0;
		$isLow = LowStockQuery::isLowStock($item->getActive(), $item->getReorderLevel(), $total);
		$openKey = self::openEpisodeKey($itemId);

		if (!$isLow) {
			$this->notifyLog->deleteByDedupeKey($openKey);
			return;
		}

		$now = $this->clock->now();
		// Already in an open low-stock episode → not a new entry.
		if ($this->notifyLog->findByDedupeKey($openKey) !== null) {
			return;
		}
		// ≤1 fire / item / 24h (covers recover-then-reenter storms).
		if ($this->notifyLog->findRecentForItem($itemId, $now - self::DEBOUNCE_SECONDS) !== null) {
			// Mark episode open without firing so subsequent dips stay quiet.
			$this->notifyLog->tryInsert($openKey, $itemId, $now);
			return;
		}
		// Claim the episode (unique key = single winner under concurrent writers).
		if (!$this->notifyLog->tryInsert($openKey, $itemId, $now)) {
			return;
		}

		$fireKey = 'lowstock:fire:' . $itemId . ':' . intdiv($now, self::DEBOUNCE_SECONDS);
		$this->notifyLog->tryInsert($fireKey, $itemId, $now);

		$recipients = $this->recipientUids();
		$params = [
			'itemName' => $item->getName(),
			'sku' => $item->getSku(),
			'totalQty' => (string)QtyScale::toDisplay($this->config, $total),
			'reorderLevel' => (string)QtyScale::toDisplay($this->config, $item->getReorderLevel()),
		];
		foreach ($recipients as $uid) {
			try {
				$n = $this->notificationManager->createNotification();
				$n->setApp(Application::APP_ID)
					->setUser($uid)
					->setDateTime(new \DateTime('@' . $now))
					->setObject('item', (string)$itemId)
					->setSubject(self::SUBJECT, $params)
					->setLink($this->urlGenerator->linkToRouteAbsolute('inventorycheck.page.item', ['id' => $itemId]));
				$this->notificationManager->notify($n);
			} catch (\Throwable $e) {
				$this->logger->warning('InventoryCheck low-stock notify failed', [
					'app' => Application::APP_ID,
					'uid' => $uid,
					'message' => $e->getMessage(),
				]);
			}
		}

		foreach ($recipients as $uid) {
			try {
				$event = $this->activityManager->generateEvent();
				$event->setApp(Application::APP_ID)
					->setType('inventory')
					->setAuthor('')
					->setAffectedUser($uid)
					->setSubject(self::SUBJECT, $params)
					->setObject('item', $itemId)
					->setTimestamp($now);
				$this->activityManager->publish($event);
			} catch (\Throwable $e) {
				$this->logger->warning('InventoryCheck low-stock activity failed', [
					'app' => Application::APP_ID,
					'uid' => $uid,
					'message' => $e->getMessage(),
				]);
			}
		}
	}

	/**
	 * @return list<string>
	 */
	private function recipientUids(): array
	{
		$uids = $this->access->getJsonIdList(self::KEY_NOTIFY_USER_IDS);
		foreach ($this->access->getJsonIdList(self::KEY_NOTIFY_GROUP_IDS) as $gid) {
			$group = $this->groupManager->get($gid);
			if ($group === null) {
				continue;
			}
			foreach ($group->getUsers() as $user) {
				$uids[] = $user->getUID();
			}
		}
		if ($uids === []) {
			$uids = array_merge(
				$this->access->getJsonIdList(AccessControlService::KEY_OFFICE_USER_IDS),
				$this->access->getJsonIdList(AccessControlService::KEY_APP_ADMINS),
			);
		}
		$out = [];
		foreach (array_unique($uids) as $uid) {
			if ($uid !== '' && $this->userManager->userExists($uid)) {
				$out[] = $uid;
			}
		}
		return $out;
	}
}
