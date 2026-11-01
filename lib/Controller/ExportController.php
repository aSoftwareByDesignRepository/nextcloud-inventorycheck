<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\CsvExportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IRequest;

/**
 * CSV export (Wave A1 / B5). Opened as a browser navigation (download link),
 * so — like {@see ItemController::label()} — it carries NoCSRFRequired.
 * Office-only: matches the office gate already enforced for movements export
 * inside {@see CsvExportService}.
 */
class ExportController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly CsvExportService $export,
		private readonly AccessControlService $access,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): DataDownloadResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireOffice($uid);
		$itemId = $this->request->getParam('itemId');
		$locationId = $this->request->getParam('locationId');
		$from = $this->request->getParam('from');
		$to = $this->request->getParam('to');
		$lang = (string)$this->request->getParam('lang', 'en');
		$reasonCode = $this->request->getParam('reasonCode');
		$result = $this->export->export(
			$uid,
			(string)$this->request->getParam('kind', ''),
			$itemId !== null && $itemId !== '' ? (int)$itemId : null,
			$locationId !== null && $locationId !== '' ? (int)$locationId : null,
			$from !== null && $from !== '' ? (int)$from : null,
			$to !== null && $to !== '' ? (int)$to : null,
			$lang,
			$reasonCode !== null && $reasonCode !== '' ? (string)$reasonCode : null,
		);
		return new DataDownloadResponse($result['body'], $result['filename'], $result['contentType']);
	}

	/**
	 * F-25 starter template: an import-shaped CSV with the canonical
	 * headers + a few generic rows for a first-run onboarding.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function starter(): DataDownloadResponse
	{
		$this->access->requireOffice($this->access->currentUserId());
		$rows = [
			['sku', 'name', 'uom', 'reorder_level', 'target_stock', 'scan_code', 'description'],
			['EXAMPLE-001', 'Example item A (replace me)', 'pcs', '10', '50', '', 'Starter template row'],
			['EXAMPLE-002', 'Example item B (replace me)', 'pcs', '5', '20', '', 'Starter template row'],
			['EXAMPLE-003', 'Example item C (replace me)', 'pcs', '2', '10', '', 'Starter template row'],
		];
		$out = fopen('php://temp', 'r+');
		foreach ($rows as $r) {
			fputcsv($out, $r, ';');
		}
		rewind($out);
		return new DataDownloadResponse((string)stream_get_contents($out), 'inventorycheck-starter.csv', 'text/csv');
	}
}
