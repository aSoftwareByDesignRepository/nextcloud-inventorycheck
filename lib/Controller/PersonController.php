<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\LoanService;
use OCA\InventoryCheck\Service\PersonService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * F2 Wave A person registry — office+ only (custody is never a
 * field-user surface). No DELETE verb: erasure is POST anonymize,
 * person rows are never hard-deleted (SPEC §9).
 */
class PersonController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly PersonService $persons,
		private readonly LoanService $loans,
		private readonly AccessControlService $access,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Register list: GET /api/persons?limit=&offset=
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		return new JSONResponse($this->persons->list(
			$uid,
			(int)$this->request->getParam('limit', 50),
			(int)$this->request->getParam('offset', 0),
		));
	}

	/**
	 * Canonical `search=` param; `q=` is a tolerated alias (picker
	 * contract — SPEC §6.1 NN-15).
	 */
	#[NoAdminRequired]
	public function search(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$term = (string)($this->request->getParam('search') ?? $this->request->getParam('q') ?? '');
		return new JSONResponse($this->persons->search($uid, $term));
	}

	#[NoAdminRequired]
	public function create(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$person = $this->persons->create(
			$uid,
			(string)$this->request->getParam('displayName', ''),
			$this->request->getParam('memberNo'),
			$this->request->getParam('note'),
			$this->request->getParam('ncUserId'),
		);
		return new JSONResponse(['person' => $person->toApi()], 201);
	}

	#[NoAdminRequired]
	public function show(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		return new JSONResponse(['person' => $this->persons->get($uid, $id)->toApi()]);
	}

	#[NoAdminRequired]
	public function update(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$person = $this->persons->update(
			$uid,
			$id,
			(string)$this->request->getParam('displayName', ''),
			$this->request->getParam('memberNo'),
			$this->request->getParam('note'),
			$this->request->getParam('ncUserId'),
			$this->toBool($this->request->getParam('active'), true),
		);
		return new JSONResponse(['person' => $person->toApi()]);
	}

	/** GDPR erasure — blocked by open loans (Conflict → 409). */
	#[NoAdminRequired]
	public function anonymize(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		return new JSONResponse(['person' => $this->persons->anonymize($uid, $id)->toApi()]);
	}

	#[NoAdminRequired]
	public function loans(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		return new JSONResponse($this->loans->forPerson(
			$uid,
			$id,
			$this->toInt($this->request->getParam('limit'), 50),
			$this->toInt($this->request->getParam('offset'), 0),
		));
	}

	private function toBool(mixed $v, bool $default): bool
	{
		if ($v === null || $v === '') {
			return $default;
		}
		if (is_bool($v)) {
			return $v;
		}
		return in_array(strtolower((string)$v), ['1', 'true', 'yes', 'on'], true);
	}

	private function toInt(mixed $v, int $default): int
	{
		return $v === null ? $default : max(0, (int)$v);
	}
}
