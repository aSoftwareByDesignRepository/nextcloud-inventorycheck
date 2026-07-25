<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\MovementService;
use OCA\InventoryCheck\Service\Pagination;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class MovementController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly MovementService $movements,
		private readonly AccessControlService $access,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse
	{
		$page = Pagination::parse($this->request->getParam('limit'), $this->request->getParam('offset'));
		$from = $this->request->getParam('from');
		$to = $this->request->getParam('to');
		$itemId = $this->request->getParam('itemId');
		$locationId = $this->request->getParam('locationId');
		return new JSONResponse($this->movements->list(
			$this->request->getParam('kind'),
			$itemId !== null && $itemId !== '' ? (int)$itemId : null,
			$locationId !== null && $locationId !== '' ? (int)$locationId : null,
			$from !== null && $from !== '' ? (int)$from : null,
			$to !== null && $to !== '' ? (int)$to : null,
			$this->request->getParam('transferGroup'),
			$page['limit'],
			$page['offset'],
		));
	}

	#[NoAdminRequired]
	public function receive(): JSONResponse
	{
		$p = $this->request->getParams();
		return new JSONResponse($this->movements->receive(
			$this->access->currentUserId(),
			(int)($p['itemId'] ?? 0),
			(int)($p['locationId'] ?? 0),
			(int)($p['qty'] ?? 0),
			isset($p['reason']) ? (string)$p['reason'] : null,
		));
	}

	#[NoAdminRequired]
	public function issue(): JSONResponse
	{
		$p = $this->request->getParams();
		return new JSONResponse($this->movements->issue(
			$this->access->currentUserId(),
			(int)($p['itemId'] ?? 0),
			(int)($p['locationId'] ?? 0),
			(int)($p['qty'] ?? 0),
			isset($p['reason']) ? (string)$p['reason'] : null,
		));
	}

	#[NoAdminRequired]
	public function transfer(): JSONResponse
	{
		$p = $this->request->getParams();
		$from = (int)($p['fromLocationId'] ?? $p['locationId'] ?? 0);
		return new JSONResponse($this->movements->transfer(
			$this->access->currentUserId(),
			(int)($p['itemId'] ?? 0),
			$from,
			(int)($p['toLocationId'] ?? 0),
			(int)($p['qty'] ?? 0),
			isset($p['reason']) ? (string)$p['reason'] : null,
		));
	}

	#[NoAdminRequired]
	public function adjust(): JSONResponse
	{
		$p = $this->request->getParams();
		return new JSONResponse($this->movements->adjust(
			$this->access->currentUserId(),
			(int)($p['itemId'] ?? 0),
			(int)($p['locationId'] ?? 0),
			(string)($p['mode'] ?? ''),
			isset($p['qty']) ? (int)$p['qty'] : null,
			isset($p['qtyDelta']) ? (int)$p['qtyDelta'] : null,
			isset($p['reason']) ? (string)$p['reason'] : null,
		));
	}

	#[NoAdminRequired]
	public function scan(): JSONResponse
	{
		$p = $this->request->getParams();
		$uid = $this->access->currentUserId();
		return new JSONResponse($this->movements->scan(
			$uid,
			(string)($p['code'] ?? ''),
			(string)($p['kind'] ?? ''),
			(int)($p['locationId'] ?? 0),
			isset($p['toLocationId']) ? (int)$p['toLocationId'] : null,
			isset($p['qty']) ? (int)$p['qty'] : null,
			isset($p['qtyDelta']) ? (int)$p['qtyDelta'] : null,
			isset($p['reason']) ? (string)$p['reason'] : null,
			$this->access->isOffice($uid),
		));
	}
}
