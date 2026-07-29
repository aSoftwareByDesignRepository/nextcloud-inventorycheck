<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\ItemPhotoService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * One primary item photo via AppData (Wave A4). GET is a plain image request
 * (used as an <img src>), so it alone carries NoCSRFRequired.
 */
class ItemPhotoController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly ItemPhotoService $photos,
		private readonly AccessControlService $access,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function upload(int $id): JSONResponse
	{
		$file = $this->request->getUploadedFile('file');
		if (!is_array($file)) {
			throw new ValidationException('upload_failed', '', [['field' => 'file', 'code' => 'upload_failed']]);
		}
		return new JSONResponse($this->photos->upload($this->access->currentUserId(), $id, $file));
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse
	{
		return new JSONResponse($this->photos->delete($this->access->currentUserId(), $id));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(int $id): DataDisplayResponse
	{
		$photo = $this->photos->read($id);
		$response = new DataDisplayResponse($photo['content'], 200, ['Content-Type' => $photo['mime']]);
		$response->cacheFor(3600, false, true);
		return $response;
	}
}
