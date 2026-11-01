<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\OrderSheetService;
use OCA\InventoryCheck\Service\QtyScale;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;

/** F-13/F-15/F-16 order sheets — office+ only (procurement surface). */
class OrderSheetController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly OrderSheetService $sheets,
		private readonly AccessControlService $access,
		private readonly IConfig $config,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse
	{
		$status = $this->request->getParam('status');
		return new JSONResponse($this->sheets->list(
			$this->access->currentUserId(),
			is_string($status) ? $status : null,
			min(200, max(1, (int)$this->request->getParam('limit', 100))),
			max(0, (int)$this->request->getParam('offset', 0)),
		));
	}

	#[NoAdminRequired]
	public function show(int $id): JSONResponse
	{
		return new JSONResponse($this->sheets->show($this->access->currentUserId(), $id));
	}

	#[NoAdminRequired]
	public function create(): JSONResponse
	{
		$p = $this->request->getParams();
		$linesIn = $p['lines'] ?? [];
		if (!is_array($linesIn)) {
			throw new ValidationException('validation_failed', '', [['field' => 'lines', 'code' => 'invalid_type']]);
		}
		$lines = [];
		foreach ($linesIn as $l) {
			if (!is_array($l)) {
				throw new ValidationException('validation_failed', '', [['field' => 'lines', 'code' => 'invalid_type']]);
			}
			$lines[] = [
				'itemId' => (int)($l['itemId'] ?? 0),
				'qty' => isset($l['qty']) ? QtyScale::toStorage($this->config, $l['qty']) : 0,
			];
		}
		$created = $this->sheets->create(
			$this->access->currentUserId(),
			isset($p['supplierId']) && $p['supplierId'] !== '' ? (int)$p['supplierId'] : null,
			isset($p['supplierName']) ? (string)$p['supplierName'] : null,
			isset($p['note']) ? (string)$p['note'] : null,
			$lines,
		);
		return new JSONResponse($created, 201);
	}

	#[NoAdminRequired]
	public function send(int $id): JSONResponse
	{
		$this->sheets->send($this->access->currentUserId(), $id);
		return $this->show($id);
	}

	#[NoAdminRequired]
	public function cancel(int $id): JSONResponse
	{
		$this->sheets->cancel($this->access->currentUserId(), $id);
		return $this->show($id);
	}

	/** F-15 print/email body — plain text, office+. */
	#[NoAdminRequired]
	public function print(int $id): JSONResponse
	{
		$this->access->requireOffice($this->access->currentUserId());
		return new JSONResponse(['text' => $this->sheets->renderText($id)]);
	}
}
