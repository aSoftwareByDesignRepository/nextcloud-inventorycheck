<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\LocationFavouriteService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Per-user favourite locations (Wave B4) — a personal preference list, not
 * gated by office/app-admin.
 */
class FavouriteController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly LocationFavouriteService $favourites,
		private readonly AccessControlService $access,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse
	{
		return new JSONResponse(['data' => $this->favourites->list($this->access->currentUserId())]);
	}

	#[NoAdminRequired]
	public function create(): JSONResponse
	{
		$locationId = (int)$this->request->getParam('locationId', 0);
		return new JSONResponse(['data' => $this->favourites->add($this->access->currentUserId(), $locationId)]);
	}

	#[NoAdminRequired]
	public function destroy(int $locationId): JSONResponse
	{
		return new JSONResponse(['data' => $this->favourites->remove($this->access->currentUserId(), $locationId)]);
	}
}
