<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\ReservationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/** Wave C reservations: create/list/cancel/convert + availability. */
class ReservationController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly ReservationService $reservations,
		private readonly AccessControlService $access,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** POST /api/reservations {itemId, locationId, qty, fromTs, toTs, personId?, eventLabel?} */
	#[NoAdminRequired]
	public function create(): JSONResponse
	{
		$r = $this->reservations->create(
			$this->access->currentUserId(),
			$this->toInt($this->request->getParam('itemId')),
			$this->toInt($this->request->getParam('locationId')),
			$this->toInt($this->request->getParam('qty')),
			$this->toInt($this->request->getParam('fromTs')),
			$this->toInt($this->request->getParam('toTs')),
			$this->nullableInt($this->request->getParam('personId')),
			$this->request->getParam('eventLabel'),
		);
		return new JSONResponse(['reservation' => $r->toApi()], 201);
	}

	/** GET /api/items/{id}/reservations */
	#[NoAdminRequired]
	public function forItem(int $id): JSONResponse
	{
		return new JSONResponse(['reservations' => $this->reservations->listForItem($this->access->currentUserId(), $id)]);
	}

	/** GET /api/items/{id}/availability?locationId=N — one sentinel keeps URL templates single-param. */
	#[NoAdminRequired]
	public function availability(int $id): JSONResponse
	{
		$this->access->requireOffice($this->access->currentUserId());
		$locationId = is_numeric($this->request->getParam('locationId')) ? (int)$this->request->getParam('locationId') : 0;
		if ($locationId <= 0) {
			throw new \OCA\InventoryCheck\Exception\ValidationException('validation_failed', '', [['field' => 'locationId', 'code' => 'validation_failed']]);
		}
		return new JSONResponse($this->reservations->availability($id, $locationId));
	}

	/** POST /api/reservations/{id}/cancel */
	#[NoAdminRequired]
	public function cancel(int $id): JSONResponse
	{
		$this->reservations->cancel($this->access->currentUserId(), $id);
		return new JSONResponse(['ok' => true]);
	}

	/** POST /api/reservations/{id}/convert {conditionOut?, note?} */
	#[NoAdminRequired]
	public function convert(int $id): JSONResponse
	{
		$out = $this->reservations->convert(
			$this->access->currentUserId(),
			$id,
			$this->request->getParam('conditionOut'),
			$this->request->getParam('note'),
		);
		return new JSONResponse(['loan' => $out['loan']->toApi()]);
	}

	private function toInt(mixed $v): int
	{
		return is_numeric($v) ? (int)$v : 0;
	}

	private function nullableInt(mixed $v): ?int
	{
		if ($v === null || $v === '') {
			return null;
		}
		return is_numeric($v) ? (int)$v : null;
	}
}
