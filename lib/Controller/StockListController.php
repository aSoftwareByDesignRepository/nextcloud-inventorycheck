<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\StockListService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IRequest;

/**
 * IV-COMPLIANCE-PACK (MH-06): point-in-time stock list as ODS + PDF.
 * Browser-navigation downloads like {@see ExportController} — office+ only.
 */
class StockListController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly StockListService $stockList,
		private readonly AccessControlService $access,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function ods(): DataDownloadResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireOffice($uid);
		$body = $this->stockList->buildOds($this->stockList->snapshot());
		return new DataDownloadResponse(
			$body,
			'inventorycheck-bestandsliste.ods',
			'application/vnd.oasis.opendocument.spreadsheet',
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function pdf(): DataDownloadResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireOffice($uid);
		$body = $this->stockList->buildPdf($this->stockList->snapshot());
		return new DataDownloadResponse(
			$body,
			'inventorycheck-bestandsliste.pdf',
			'application/pdf',
		);
	}
}
