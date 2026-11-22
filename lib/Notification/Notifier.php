<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Notification;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\LotExpiryNotifyService;
use OCA\InventoryCheck\Service\LowStockNotifyService;
use OCA\InventoryCheck\Service\RestockDigestService;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

final class Notifier implements INotifier
{
	public function __construct(
		private readonly IFactory $l10nFactory,
		private readonly IURLGenerator $url,
	) {
	}

	public function getID(): string
	{
		return Application::APP_ID;
	}

	public function getName(): string
	{
		return 'InventoryCheck';
	}

	public function prepare(INotification $notification, string $languageCode): INotification
	{
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode !== '' ? $languageCode : null);
		$subject = $notification->getSubject();
		/** @var array<string, mixed> $p */
		$p = $notification->getSubjectParameters();

		if ($subject === LowStockNotifyService::SUBJECT) {
			$name = (string)($p['itemName'] ?? '');
			$sku = (string)($p['sku'] ?? '');
			$total = (string)($p['totalQty'] ?? '');
			$reorder = (string)($p['reorderLevel'] ?? '');
			$notification->setParsedSubject($l->t('Low stock: %s', [$name !== '' ? $name : $sku]));
			$notification->setParsedMessage($l->t(
				'%1$s (%2$s) is below reorder level: %3$s on hand, reorder at %4$s.',
				[$name, $sku, $total, $reorder],
			));
			$objectId = $notification->getObjectId();
			if (ctype_digit($objectId)) {
				$notification->setLink($this->url->linkToRouteAbsolute('inventorycheck.page.item', ['id' => (int)$objectId]));
			}
			return $notification;
		}

		if ($subject === RestockDigestService::SUBJECT) {
			$count = (string)($p['count'] ?? '0');
			$onOrder = (string)($p['onOrder'] ?? '0');
			$expiring = (string)($p['expiring'] ?? '0');
			$notification->setParsedSubject($l->t('Restock digest: %s items low', [$count]));
			$notification->setParsedMessage($l->t(
				'%1$s items below reorder, %2$s on order, %3$s lots expiring soon.',
				[$count, $onOrder, $expiring],
			));
			$notification->setLink($this->url->linkToRouteAbsolute('inventorycheck.page.dashboard'));
			return $notification;
		}

		if ($subject === LotExpiryNotifyService::SUBJECT) {
			$name = (string)($p['itemName'] ?? '');
			$sku = (string)($p['sku'] ?? '');
			$lot = (string)($p['lotCode'] ?? '');
			$qty = (string)($p['qty'] ?? '');
			$expired = ($p['expired'] ?? '0') === '1';
			$label = $name !== '' ? $name : $sku;
			$notification->setParsedSubject(
				$expired
					? $l->t('Lot expired: %s', [$label])
					: $l->t('Lot expiring soon: %s', [$label]),
			);
			$notification->setParsedMessage(
				$expired
					? $l->t('Lot %1$s of %2$s (%3$s) has expired — %4$s left on hand.', [$lot, $name, $sku, $qty])
					: $l->t('Lot %1$s of %2$s (%3$s) expires in %4$s days — %5$s on hand.', [$lot, $name, $sku, (string)($p['daysLeft'] ?? ''), $qty]),
			);
			$notification->setLink($this->url->linkToRouteAbsolute('inventorycheck.page.item', ['id' => (int)$notification->getObjectId()]));
			return $notification;
		}

		throw new UnknownNotificationException();
	}
}
