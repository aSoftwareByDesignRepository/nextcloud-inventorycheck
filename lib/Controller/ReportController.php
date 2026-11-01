<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\ReportService;
use OCA\InventoryCheck\Service\QtyScale;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;

/** F-12 read-only estimate reports — office+ only (money surface). */
class ReportController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly ReportService $reports,
		private readonly AccessControlService $access,
		private readonly IConfig $config,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function valuation(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireOffice($uid);
		return new JSONResponse($this->reports->valuation(QtyScale::factor($this->config)));
	}
}
