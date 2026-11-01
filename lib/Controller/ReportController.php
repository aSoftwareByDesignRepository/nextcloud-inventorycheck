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

	/** F-12 movement-value report — office+; period via from/to epoch params. */
	#[NoAdminRequired]
	public function movementValue(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireOffice($uid);
		$rawFrom = $this->request->getParam('from');
		$rawTo = $this->request->getParam('to');
		foreach (['from' => $rawFrom, 'to' => $rawTo] as $field => $raw) {
			if ($raw !== null && !(is_int($raw) || (is_string($raw) && preg_match('/^-?\d+$/', $raw) === 1))) {
				throw new \OCA\InventoryCheck\Exception\ValidationException('validation_failed', '', [['field' => $field, 'code' => 'validation_failed']]);
			}
		}
		$from = (int)($rawFrom ?? time() - 30 * 86400);
		$to = (int)($rawTo ?? time());
		if ($from < 0 || $to < $from) {
			throw new \OCA\InventoryCheck\Exception\ValidationException('validation_failed', '', [['field' => 'to', 'code' => 'validation_failed']]);
		}
		return new JSONResponse($this->reports->movementValue($from, $to));
	}
}
