<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Dashboard;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\LowStockService;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IURLGenerator;
use OCP\IL10N;

/** F-21 dashboard widget — top low-stock items, money-free payload. */
class LowStockWidget implements IAPIWidgetV2, IButtonWidget
{
	public function __construct(
		private readonly LowStockService $lowStock,
		private readonly AccessControlService $access,
		private readonly IURLGenerator $urlGenerator,
		private readonly IL10N $l10n,
	) {
	}

	public function getId(): string
	{
		return 'inventorycheck-low-stock';
	}

	public function getTitle(): string
	{
		return $this->l10n->t('Low stock');
	}

	public function getOrder(): int
	{
		return 10;
	}

	public function getIconClass(): string
	{
		return 'icon-inventorycheck';
	}

	public function getUrl(): ?string
	{
		return $this->urlGenerator->linkToRoute('inventorycheck.page.dashboard');
	}

	public function load(): void
	{
		// API-driven widget — nothing to inject eagerly.
	}

	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems
	{
		$empty = $this->l10n->t('Nothing is below reorder level.');
		try {
			$rows = $this->lowStock->list($userId, min(20, $limit), 0)['data'] ?? [];
		} catch (\Throwable) {
			return new WidgetItems([], $empty);
		}
		$icon = $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg'),
		);
		$items = [];
		foreach ($rows as $r) {
			$item = $r['item'] ?? [];
			$items[] = new WidgetItem(
				(string)($item['name'] ?? ''),
				(string)($r['totalQty'] ?? ''),
				$this->urlGenerator->linkToRoute('inventorycheck.page.item', ['id' => (int)($item['id'] ?? 0)]),
				$icon,
				(string)($item['id'] ?? ''),
			);
		}
		return new WidgetItems($items, $empty);
	}

	public function getWidgetButtons(string $userId): array
	{
		return [
			new WidgetButton('more', $this->getUrl() ?? '', $this->l10n->t('Open reorder view')),
		];
	}
}
