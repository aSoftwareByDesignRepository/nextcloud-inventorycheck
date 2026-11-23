<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\LoanRequestService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/** Wave C office request queue: list / approve(→loan) / decline. */
class LoanRequestController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly LoanRequestService $requests,
		private readonly AccessControlService $access,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** GET /api/loan-requests — pending queue */
	#[NoAdminRequired]
	public function index(): JSONResponse
	{
		return new JSONResponse(['requests' => $this->requests->listPending($this->access->currentUserId())]);
	}

	/** POST /api/loan-requests/{id}/approve {locationId?, conditionOut?, note?} */
	#[NoAdminRequired]
	public function approve(int $id): JSONResponse
	{
		$out = $this->requests->approve(
			$this->access->currentUserId(),
			$id,
			$this->nullableInt($this->request->getParam('locationId')),
			$this->request->getParam('conditionOut'),
			$this->request->getParam('note'),
		);
		return new JSONResponse([
			'request' => $out['request']->toApi(),
			'loan' => $out['loan']->toApi(),
		]);
	}

	/** POST /api/loan-requests/{id}/decline {note?} */
	#[NoAdminRequired]
	public function decline(int $id): JSONResponse
	{
		$r = $this->requests->decline(
			$this->access->currentUserId(),
			$id,
			$this->request->getParam('note'),
		);
		return new JSONResponse(['request' => $r->toApi()]);
	}

	private function nullableInt(mixed $v): ?int
	{
		if ($v === null || $v === '') {
			return null;
		}
		return is_numeric($v) ? (int)$v : null;
	}
}
