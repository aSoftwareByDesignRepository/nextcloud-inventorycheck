<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Notification;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\LowStockNotifyService;
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

		if ($subject !== LowStockNotifyService::SUBJECT) {
			throw new UnknownNotificationException();
		}

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
}
