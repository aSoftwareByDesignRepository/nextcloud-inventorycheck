<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\AppInfo\Application;
use OCP\Activity\IManager as ActivityManager;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Notification\IManager as NotificationManager;
use Psr\Log\LoggerInterface;

/**
 * F-08 weekly reorder digest — one notification per recipient with the
 * low-stock count. Money shaping is per-recipient (TH-07): the summary
 * carries counts only; the linked reorder view applies office gates.
 */
class RestockDigestService
{
	private const SUBJECT = 'restock_digest';
	private const KEY_LAST_RUN = 'restock_digest_last_run';
	private const INTERVAL = 7 * 86400;

	public function __construct(
		private readonly NotificationManager $notificationManager,
		private readonly ActivityManager $activityManager,
		private readonly LowStockService $lowStock,
		private readonly AccessControlService $access,
		private readonly IUserManager $userManager,
		private readonly IURLGenerator $urlGenerator,
		private readonly IConfig $config,
		private readonly RestockConfig $restock,
		private readonly Clock $clock,
		private readonly LoggerInterface $logger,
		private readonly ?\OCA\InventoryCheck\Db\LotMapper $lots = null,
	) {
	}

	public function due(): bool
	{
		if (!$this->restock->digestEnabled()) {
			return false;
		}
		$last = (int)$this->config->getAppValue(Application::APP_ID, self::KEY_LAST_RUN, '0');
		return $this->clock->now() - $last >= self::INTERVAL;
	}

	public function run(): void
	{
		if (!$this->due()) {
			return;
		}
		$this->config->setAppValue(Application::APP_ID, self::KEY_LAST_RUN, (string)$this->clock->now());

		foreach ($this->recipientUids() as $uid) {
			try {
				$rows = $this->lowStock->list($uid, 500, 0)['rows'] ?? [];
				if (count($rows) === 0) {
					continue;
				}
				// F-22: health view — on-order + expiring-soon lots alongside low count.
				$onOrder = 0;
				foreach ($rows as $r) {
					if (($r['item']['onOrderQty'] ?? null) !== null) {
						$onOrder++;
					}
				}
				$expiring = $this->lots !== null && $this->restock !== null
					? count($this->lots->expiringWithin($this->clock->now(), $this->restock->expiryWarnDays() * 86400))
					: 0;
				$now = $this->clock->now();
				$params = [
					'count' => (string)count($rows),
					'onOrder' => (string)$onOrder,
					'expiring' => (string)$expiring,
				];
				$n = $this->notificationManager->createNotification();
				$n->setApp(Application::APP_ID)
					->setUser($uid)
					->setDateTime(new \DateTime('@' . $now))
					->setObject('restock_digest', (string)$now)
					->setSubject(self::SUBJECT, $params)
					->setLink($this->urlGenerator->linkToRouteAbsolute('inventorycheck.page.reorder'));
				$this->notificationManager->notify($n);
			} catch (\Throwable $e) {
				$this->logger->warning('InventoryCheck restock digest failed', [
					'app' => Application::APP_ID,
					'uid' => $uid,
					'message' => $e->getMessage(),
				]);
			}
		}
	}

	/** @return list<string> */
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
