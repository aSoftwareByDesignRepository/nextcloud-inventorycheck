<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\CustomFieldService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * IV-CUSTOM-FIELDS — field-definition admin CRUD (app-admin only, MH-02).
 * All write errors travel the standard error envelope (fields map on
 * ValidationException feeds the field-error pack).
 */
class FieldController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly CustomFieldService $fields,
		private readonly AccessControlService $access,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse
	{
		return new JSONResponse(['data' => $this->fields->listDefs($this->access->currentUserId())]);
	}

	#[NoAdminRequired]
	public function create(): JSONResponse
	{
		return new JSONResponse($this->fields->createDef($this->access->currentUserId(), $this->request->getParams()), 201);
	}

	#[NoAdminRequired]
	public function update(int $id): JSONResponse
	{
		return new JSONResponse($this->fields->updateDef($this->access->currentUserId(), $id, $this->request->getParams()));
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse
	{
		$affected = $this->fields->deleteDef($this->access->currentUserId(), $id);
		return new JSONResponse(['deleted' => true, 'valuesRemoved' => $affected]);
	}

	/** Pre-delete count for the destructive-confirm copy (NN-05). */
	#[NoAdminRequired]
	public function valueCount(int $id): JSONResponse
	{
		$this->access->requireAppAdmin($this->access->currentUserId());
		return new JSONResponse(['count' => $this->fields->valueCount($id)]);
	}
}
