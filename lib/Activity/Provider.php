<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Activity;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\LowStockNotifyService;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IProvider;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;

/**
 * Wave A3: render low-stock activity events published by {@see LowStockNotifyService}.
 */
class Provider implements IProvider
{
	public function __construct(
		private readonly IFactory $l10nFactory,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	public function parse($language, IEvent $event, ?IEvent $previousEvent = null): IEvent
	{
		if ($event->getApp() !== Application::APP_ID) {
			throw new UnknownActivityException();
		}
		if ($event->getSubject() !== LowStockNotifyService::SUBJECT) {
			throw new UnknownActivityException();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $language !== '' ? $language : null);
		$params = $event->getSubjectParameters();
		$name = (string)($params['itemName'] ?? '');
		$sku = (string)($params['sku'] ?? '');
		$total = (string)($params['totalQty'] ?? '');
		$reorder = (string)($params['reorderLevel'] ?? '');
		$label = $name !== '' ? $name : $sku;

		$event->setParsedSubject($l->t('Low stock: %s', [$label]));
		$event->setParsedMessage($l->t(
			'%1$s (%2$s) is below reorder level: %3$s on hand, reorder at %4$s.',
			[$name, $sku, $total, $reorder],
		));
		$event->setIcon($this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath(Application::APP_ID, 'app.svg'),
		));
		$objectId = $event->getObjectId();
		if ($objectId > 0) {
			$event->setLink($this->urlGenerator->linkToRouteAbsolute(
				'inventorycheck.page.item',
				['id' => $objectId],
			));
		}
		if ($previousEvent !== null) {
			$event->setChildEvent($previousEvent);
		}
		return $event;
	}
}
