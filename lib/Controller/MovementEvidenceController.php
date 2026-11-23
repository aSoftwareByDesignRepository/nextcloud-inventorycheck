<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\MovementEvidenceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\ISession;

/**
 * C-2: Lieferschein photo on a receive movement. Web upload/delete are
 * office-only (MH-02); GET is a plain image request (NoCSRFRequired like the
 * item-photo route). Path stays server-side — NN-01.
 */
class MovementEvidenceController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly MovementEvidenceService $evidence,
		private readonly AccessControlService $access,
		private readonly ISession $session,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function upload(int $id): JSONResponse
	{
		$this->access->requireOffice($this->access->currentUserId());
		$file = $this->request->getUploadedFile('file');
		if (!is_array($file)) {
			throw new ValidationException('upload_failed', '', [['field' => 'file', 'code' => 'upload_failed']]);
		}
		$mov = $this->evidence->upload($this->access->currentUserId(), $id, $file);
		return new JSONResponse(['movement' => $mov->toApi()]);
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse
	{
		$mov = $this->evidence->delete($this->access->currentUserId(), $id);
		return new JSONResponse(['movement' => $mov->toApi()]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(int $id): DataDisplayResponse
	{
		$this->session->close();
		$photo = $this->evidence->read($this->access->currentUserId(), $id);
		$response = new DataDisplayResponse($photo['content'], 200, ['Content-Type' => $photo['mime']]);
		$response->cacheFor(3600, false, true);
		return $response;
	}
}
