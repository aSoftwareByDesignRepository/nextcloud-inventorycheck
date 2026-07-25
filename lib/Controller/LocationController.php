<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\Pagination;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class LocationController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly LocationService $locations,
		private readonly AccessControlService $access,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse
	{
		$page = Pagination::parse($this->request->getParam('limit'), $this->request->getParam('offset'));
		$activeRaw = $this->request->getParam('active');
		$active = $activeRaw === null || $activeRaw === '' ? null : filter_var($activeRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
		return new JSONResponse($this->locations->list($active, $page['limit'], $page['offset']));
	}

	#[NoAdminRequired]
	public function show(int $id): JSONResponse
	{
		return new JSONResponse($this->locations->get($id));
	}

	#[NoAdminRequired]
	public function create(): JSONResponse
	{
		return new JSONResponse($this->locations->create($this->access->currentUserId(), $this->request->getParams()));
	}

	#[NoAdminRequired]
	public function update(int $id): JSONResponse
	{
		return new JSONResponse($this->locations->update($this->access->currentUserId(), $id, $this->request->getParams()));
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse
	{
		$this->locations->delete($this->access->currentUserId(), $id);
		return new JSONResponse(['ok' => true]);
	}
}
