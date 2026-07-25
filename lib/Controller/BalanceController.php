<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\BalanceService;
use OCA\InventoryCheck\Service\Pagination;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class BalanceController extends Controller
{
	public function __construct(IRequest $request, private readonly BalanceService $balances)
	{
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse
	{
		$page = Pagination::parse($this->request->getParam('limit'), $this->request->getParam('offset'));
		$itemId = $this->request->getParam('itemId');
		$locationId = $this->request->getParam('locationId');
		$nonZero = filter_var($this->request->getParam('nonZero', '0'), FILTER_VALIDATE_BOOLEAN);
		$negativeOnly = filter_var($this->request->getParam('negative', '0'), FILTER_VALIDATE_BOOLEAN);
		return new JSONResponse($this->balances->list(
			$itemId !== null && $itemId !== '' ? (int)$itemId : null,
			$locationId !== null && $locationId !== '' ? (int)$locationId : null,
			$nonZero,
			$page['limit'],
			$page['offset'],
			$negativeOnly,
		));
	}
}
