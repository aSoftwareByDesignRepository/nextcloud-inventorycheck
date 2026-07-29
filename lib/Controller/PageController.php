<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\LicenseService;
use OCA\InventoryCheck\Service\LocationAclService;
use OCA\InventoryCheck\Service\LowStockService;
use OCA\InventoryCheck\Service\QtyScale;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Util;

class PageController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly AccessControlService $access,
		private readonly IUserSession $userSession,
		private readonly IURLGenerator $urlGenerator,
		private readonly IFactory $l10nFactory,
		private readonly IConfig $config,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function dashboard(): TemplateResponse
	{
		return $this->page('dashboard', 'Dashboard', 'Low stock and recent movements');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function items(): TemplateResponse
	{
		return $this->page('items', 'Items', 'Stock-keeping units and scan codes');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function item(int $id): TemplateResponse
	{
		return $this->page('item-detail', 'Item', 'Balances and label', $id);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function locations(): TemplateResponse
	{
		return $this->page('locations', 'Locations', 'Warehouses, vans, and site boxes');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function location(int $id): TemplateResponse
	{
		return $this->page('location-detail', 'Location', 'Stock at this place', $id);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function movements(): TemplateResponse
	{
		return $this->page('movements', 'Movements', 'Append-only booking history');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function stocktake(): TemplateResponse
	{
		return $this->page('stocktake', 'Stocktake', 'Cycle counts and Inventur campaigns');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function stocktakeCampaign(int $id): TemplateResponse
	{
		return $this->page('stocktake', 'Stocktake', 'Cycle counts and Inventur campaigns', $id);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settings(): TemplateResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireAppAdmin($uid);
		return $this->page('settings', 'Settings', 'Access, stock policy, license, support');
	}

	private function page(string $pageId, string $titleKey, string $hintKey, ?int $entityId = null): TemplateResponse
	{
		Util::addScript(Application::APP_ID, 'app');
		Util::addStyle(Application::APP_ID, 'app');

		$l = $this->l10nFactory->get(Application::APP_ID);
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		$isAppAdmin = $this->access->isAppAdmin($uid);
		$isSystemAdmin = $this->access->isSystemAdmin($uid);
		$isOffice = $this->access->isOffice($uid);

		$urls = [
			'pages' => [
				'dashboard' => $this->urlGenerator->linkToRoute('inventorycheck.page.dashboard'),
				'items' => $this->urlGenerator->linkToRoute('inventorycheck.page.items'),
				'locations' => $this->urlGenerator->linkToRoute('inventorycheck.page.locations'),
				'movements' => $this->urlGenerator->linkToRoute('inventorycheck.page.movements'),
				'stocktake' => $this->urlGenerator->linkToRoute('inventorycheck.page.stocktake'),
				'stocktakeCampaign' => $this->urlGenerator->linkToRoute('inventorycheck.page.stocktakeCampaign', ['id' => 0]),
				'settings' => $this->urlGenerator->linkToRoute('inventorycheck.page.settings'),
			],
			'api' => [
				'items' => $this->urlGenerator->linkToRoute('inventorycheck.item.index'),
				'itemByCode' => $this->urlGenerator->linkToRoute('inventorycheck.item.byCode', ['code' => '__CODE__']),
				'itemLabel' => $this->urlGenerator->linkToRoute('inventorycheck.item.label', ['id' => 0]),
				'itemLabelPrint' => $this->urlGenerator->linkToRoute('inventorycheck.item.labelPrint', ['id' => 0]),
				'itemBulkLabels' => $this->urlGenerator->linkToRoute('inventorycheck.item.bulkLabels'),
				'itemPhoto' => $this->urlGenerator->linkToRoute('inventorycheck.itemPhoto.show', ['id' => 0]),
				'itemPhotoUpload' => $this->urlGenerator->linkToRoute('inventorycheck.itemPhoto.upload', ['id' => 0]),
				'itemPhotoDelete' => $this->urlGenerator->linkToRoute('inventorycheck.itemPhoto.destroy', ['id' => 0]),
				'locations' => $this->urlGenerator->linkToRoute('inventorycheck.location.index'),
				'balances' => $this->urlGenerator->linkToRoute('inventorycheck.balance.index'),
				'movements' => $this->urlGenerator->linkToRoute('inventorycheck.movement.index'),
				'movementReceive' => $this->urlGenerator->linkToRoute('inventorycheck.movement.receive'),
				'movementIssue' => $this->urlGenerator->linkToRoute('inventorycheck.movement.issue'),
				'movementTransfer' => $this->urlGenerator->linkToRoute('inventorycheck.movement.transfer'),
				'movementAdjust' => $this->urlGenerator->linkToRoute('inventorycheck.movement.adjust'),
				'lowStock' => $this->urlGenerator->linkToRoute('inventorycheck.lowStock.index'),
				'lowStockPerLocation' => $this->urlGenerator->linkToRoute('inventorycheck.lowStock.perLocation'),
				'export' => $this->urlGenerator->linkToRoute('inventorycheck.export.index'),
				'importDryRun' => $this->urlGenerator->linkToRoute('inventorycheck.import.dryRun'),
				'importCommit' => $this->urlGenerator->linkToRoute('inventorycheck.import.commit'),
				'cycleCounts' => $this->urlGenerator->linkToRoute('inventorycheck.cycleCount.index'),
				'cycleCountStart' => $this->urlGenerator->linkToRoute('inventorycheck.cycleCount.start', ['id' => 0]),
				'cycleCountSetCount' => $this->urlGenerator->linkToRoute('inventorycheck.cycleCount.setCount', ['lineId' => 0]),
				'cycleCountClose' => $this->urlGenerator->linkToRoute('inventorycheck.cycleCount.close', ['id' => 0]),
				'favouriteLocations' => $this->urlGenerator->linkToRoute('inventorycheck.favourite.index'),
				'favouriteLocationRemove' => $this->urlGenerator->linkToRoute('inventorycheck.favourite.destroy', ['locationId' => 0]),
				'flangeStatus' => $this->urlGenerator->linkToRoute('inventorycheck.flange.status'),
				'flangeSettings' => $this->urlGenerator->linkToRoute('inventorycheck.flange.saveSettings'),
				'config' => $this->urlGenerator->linkToRoute('inventorycheck.config.index'),
				'configAccess' => $this->urlGenerator->linkToRoute('inventorycheck.config.saveAccess'),
				'configOffice' => $this->urlGenerator->linkToRoute('inventorycheck.config.saveOffice'),
				'configNotify' => $this->urlGenerator->linkToRoute('inventorycheck.config.saveNotify'),
				'configFractional' => $this->urlGenerator->linkToRoute('inventorycheck.config.saveFractional'),
				'configLocationAcl' => $this->urlGenerator->linkToRoute('inventorycheck.config.locationAcl'),
				'license' => $this->urlGenerator->linkToRoute('inventorycheck.license.show'),
				'licenseSeats' => $this->urlGenerator->linkToRoute('inventorycheck.license.seats'),
				'licenseDevices' => $this->urlGenerator->linkToRoute('inventorycheck.license.devices'),
			],
		];

		$params = [
			'pageId' => $pageId,
			'pageTitle' => $l->t($titleKey),
			'pageHint' => $l->t($hintKey),
			'entityId' => $entityId,
			'currentUserId' => $uid,
			'isAppAdmin' => $isAppAdmin,
			'isSystemAdmin' => $isSystemAdmin,
			'isOffice' => $isOffice,
			'mobileAppStatus' => LicenseService::MOBILE_APP_STATUS,
			'urlsJson' => json_encode($urls, JSON_UNESCAPED_SLASHES),
			'allowNegativeStock' => $this->access->allowNegativeStock(),
			'locationReorderHintEnabled' => $this->config->getAppValue(
				Application::APP_ID,
				LowStockService::KEY_LOCATION_REORDER_HINT_ENABLED,
				'0',
			) === '1',
			'qtyScale' => QtyScale::current($this->config),
			'locationAclEnabled' => $this->config->getAppValue(
				Application::APP_ID,
				LocationAclService::KEY_ENABLED,
				'0',
			) === '1',
			'timezone' => $this->config->getUserValue($uid, 'core', 'timezone', $this->config->getSystemValueString('default_timezone', 'UTC')) ?: 'UTC',
			'roleLabel' => $isAppAdmin ? $l->t('Administrator') : ($isOffice ? $l->t('Office') : $l->t('Field')),
		];

		if ($pageId === 'settings') {
			$params['supportUsLicenseUrl'] = $this->urlGenerator->linkToRouteAbsolute('inventorycheck.page.settings') . '#iv-license';
		}

		$template = match ($pageId) {
			'item-detail' => 'item-detail',
			'location-detail' => 'location-detail',
			default => $pageId,
		};
		$response = new TemplateResponse(Application::APP_ID, $template, $params);
		$response->renderAs(TemplateResponse::RENDER_AS_USER);
		return $response;
	}
}
