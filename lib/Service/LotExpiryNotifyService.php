<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\Lot;
use OCA\InventoryCheck\Db\LotMapper;
use OCA\InventoryCheck\Db\MovementMapper;
use OCA\InventoryCheck\Db\NotifyLogMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * IV-LOT-EXPIRY-PACK (MH-04): daily scan — in-stock lots inside the warn
 * window notify office users once per lot+recipient+day (notify-log
 * dedupe; a second run in the same day is a no-op — AC-12).
 *
 * Recipients are office users only (NN-02) — expiry detail is internal
 * stock information, never pushed to field users.
 */
final class LotExpiryNotifyService
{
	public const SUBJECT = 'lot_expiry';

	public function __construct(
		private readonly LotMapper $lots,
		private readonly MovementMapper $movements,
		private readonly ItemMapper $items,
		private readonly NotifyLogMapper $notifyLog,
		private readonly AccessControlService $access,
		private readonly IUserManager $userManager,
		private readonly INotificationManager $notificationManager,
		private readonly IURLGenerator $urlGenerator,
		private readonly RestockConfig $restock,
		private readonly Clock $clock,
		private readonly LoggerInterface $logger,
	) {
	}

	public function run(): void
	{
		$days = $this->restock->expiryWarnDays();
		if ($days === 0) {
			return; // watch off
		}
		$now = $this->clock->now();
		$lots = $this->lots->expiringWithin($now, $days * 86400, 1000);
		if ($lots === []) {
			return;
		}
		$itemIds = array_values(array_unique(array_map(static fn (Lot $l): int => $l->getItemId(), $lots)));
		// Global truth for the office recipients — the job runs unscoped.
		$qtyMap = $this->movements->sumQtyDeltaByItemLotMap($itemIds, null);
		$meta = [];
		foreach ($lots as $lot) {
			$qty = $qtyMap[$lot->getItemId()][$lot->getLotCode()] ?? 0;
			if ($qty <= 0) {
				continue; // NN-04: zero-qty lots never notify
			}
			foreach ($this->recipientUids() as $uid) {
				$key = sprintf('lotexp:%d:%s:%s', (int)$lot->getId(), $uid, gmdate('Ymd', $now));
				try {
					if (!$this->notifyLog->tryInsert($key, $lot->getItemId(), $now)) {
						continue; // already notified today
					}
					[$name, $sku] = $this->itemMeta($meta, $lot->getItemId());
					$expired = (int)$lot->getExpiresAt() < $now;
					$n = $this->notificationManager->createNotification();
					$n->setApp(Application::APP_ID)
						->setUser($uid)
						->setDateTime(new \DateTime('@' . $now))
						->setObject('lot_expiry', (string)$lot->getItemId())
						->setSubject(self::SUBJECT, [
							'itemName' => $name,
							'sku' => $sku,
							'lotCode' => $lot->getLotCode(),
							'qty' => (string)$qty,
							'daysLeft' => (string)max(0, (int)floor(((int)$lot->getExpiresAt() - $now) / 86400)),
							'expired' => $expired ? '1' : '0',
						])
						->setLink($this->urlGenerator->linkToRouteAbsolute('inventorycheck.page.item', ['id' => $lot->getItemId()]));
					$this->notificationManager->notify($n);
				} catch (\Throwable $e) {
					$this->logger->warning('InventoryCheck lot-expiry notify failed', [
						'app' => Application::APP_ID,
						'lotId' => $lot->getId(),
						'uid' => $uid,
						'message' => $e->getMessage(),
					]);
				}
			}
		}
	}

	/**
	 * @param array<int, array{0: string, 1: string}> $meta
	 * @return array{0: string, 1: string} [name, sku]
	 */
	private function itemMeta(array &$meta, int $itemId): array
	{
		if (!isset($meta[$itemId])) {
			try {
				$item = $this->items->findById($itemId);
				$meta[$itemId] = [$item->getName(), $item->getSku()];
			} catch (DoesNotExistException) {
				$meta[$itemId] = ['', ''];
			}
		}
		return $meta[$itemId];
	}

	/** Office users only (NN-02) — same recipient policy as the restock digest. */
	private function recipientUids(): array
	{
		$uids = array_merge(
			$this->access->getJsonIdList(AccessControlService::KEY_OFFICE_USER_IDS),
			$this->access->getJsonIdList(AccessControlService::KEY_APP_ADMINS),
		);
		$out = [];
		foreach (array_unique($uids) as $uid) {
			if ($uid !== '' && $this->userManager->userExists($uid)) {
				$out[] = $uid;
			}
		}
		return $out;
	}
}
