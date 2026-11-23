<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\LoanService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * F2 Wave A loan lifecycle — office+ custody writes. Returns the
 * decorated loan + movement so the UI can refresh stock and the
 * "Ausgeliehen" list in one render.
 */
class LoanController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly LoanService $loans,
		private readonly AccessControlService $access,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** Checkout: POST /api/loans {itemId, locationId, personId, qty, dueAt, lotCode, conditionOut, note} */
	#[NoAdminRequired]
	public function create(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$result = $this->loans->checkout(
			$uid,
			$this->toInt($this->request->getParam('itemId'), 0),
			$this->toInt($this->request->getParam('locationId'), 0),
			$this->toInt($this->request->getParam('personId'), 0),
			$this->toInt($this->request->getParam('qty'), 0),
			$this->nullableInt($this->request->getParam('dueAt')),
			$this->request->getParam('lotCode'),
			$this->request->getParam('conditionOut'),
			$this->request->getParam('note'),
		);
		$loan = $result['loan'];
		return new JSONResponse([
			'loan' => $loan->toApi(),
			'movement' => $this->movementToApi($result['movement']),
		], 201);
	}

	/** Check-in: POST /api/loans/{id}/checkin {qty, conditionIn, note} */
	#[NoAdminRequired]
	public function checkin(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$result = $this->loans->checkin(
			$uid,
			$id,
			$this->toInt($this->request->getParam('qty'), 0),
			$this->request->getParam('conditionIn'),
			$this->request->getParam('note'),
		);
		return new JSONResponse([
			'loan' => $result['loan']->toApi(),
			'movement' => $this->movementToApi($result['movement']),
		]);
	}

	/** Terminal mark-lost: POST /api/loans/{id}/lost {note} */
	#[NoAdminRequired]
	public function lost(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		return new JSONResponse(['loan' => $this->loans->markLost($uid, $id, $this->request->getParam('note'))->toApi()]);
	}

	/** Overview list + counts for the start-page card. */
	#[NoAdminRequired]
	public function index(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		return new JSONResponse($this->loans->overview(
			$uid,
			$this->request->getParam('overdueOnly') !== null,
			$this->toInt($this->request->getParam('limit'), 50),
			$this->toInt($this->request->getParam('offset'), 0),
		));
	}

	/** Item detail "Ausgeliehen" section. */
	#[NoAdminRequired]
	public function forItem(int $itemId): JSONResponse
	{
		$uid = $this->access->currentUserId();
		return new JSONResponse(['loans' => $this->loans->openForItem($uid, $itemId)]);
	}

	private function movementToApi(\OCA\InventoryCheck\Db\Movement $m): array
	{
		return [
			'id' => $m->getId(),
			'itemId' => $m->getItemId(),
			'locationId' => $m->getLocationId(),
			'kind' => $m->getKind(),
			'qtyDelta' => $m->getQtyDelta(),
			'qtyAfter' => $m->getQtyAfter(),
			'refType' => $m->getRefType(),
			'refId' => $m->getRefId(),
			'lotCode' => $m->getLotCode(),
			'createdAt' => $m->getCreatedAt(),
			'createdBy' => $m->getCreatedBy(),
		];
	}

	private function toInt(mixed $v, int $default): int
	{
		return $v === null ? $default : (int)$v;
	}

	private function nullableInt(mixed $v): ?int
	{
		if ($v === null || $v === '') {
			return null;
		}
		$n = (int)$v;
		return $n > 0 ? $n : null;
	}
}
