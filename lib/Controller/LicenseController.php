<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\LicenseService;
use OCA\InventoryCheck\Service\LocationAclService;
use OCA\InventoryCheck\Service\Pagination;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class LicenseController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly LicenseService $license,
		private readonly AccessControlService $access,
		private readonly LocationAclService $locationAcl,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function show(): JSONResponse
	{
		$this->access->requireAppAdmin($this->access->currentUserId());
		return new JSONResponse($this->license->status());
	}

	#[NoAdminRequired]
	public function apply(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireAppAdmin($uid);
		$key = (string)$this->request->getParam('key', '');
		return new JSONResponse($this->license->apply($uid, $key));
	}

	#[NoAdminRequired]
	public function remove(): JSONResponse
	{
		$this->access->requireAppAdmin($this->access->currentUserId());
		return new JSONResponse($this->license->remove());
	}

	#[NoAdminRequired]
	public function seats(): JSONResponse
	{
		$this->access->requireAppAdmin($this->access->currentUserId());
		$page = Pagination::parse($this->request->getParam('limit'), $this->request->getParam('offset'));
		return new JSONResponse($this->license->listSeats($page['limit'], $page['offset']));
	}

	#[NoAdminRequired]
	public function assignSeat(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireAppAdmin($uid);
		return new JSONResponse($this->license->assignSeat($uid, $this->request->getParam('uid')));
	}

	#[NoAdminRequired]
	public function removeSeat(string $uid): JSONResponse
	{
		$this->access->requireAppAdmin($this->access->currentUserId());
		$this->license->removeSeat($uid);
		return new JSONResponse(['ok' => true]);
	}

	#[NoAdminRequired]
	public function devices(): JSONResponse
	{
		$this->access->requireAppAdmin($this->access->currentUserId());
		$page = Pagination::parse($this->request->getParam('limit'), $this->request->getParam('offset'));
		return new JSONResponse($this->license->listDevices($page['limit'], $page['offset']));
	}

	#[NoAdminRequired]
	public function createDevice(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireAppAdmin($uid);
		$label = (string)$this->request->getParam('label', '');

		// Validate bind targets before creating the slot so a bad locationIds
		// payload cannot leave an unbound (org-wide BC) scanner behind.
		$bindIds = null;
		$rawIds = $this->request->getParam('locationIds');
		if (is_array($rawIds) && $rawIds !== []) {
			$bindIds = $this->locationAcl->normalizeLocationIds($rawIds);
		}

		$created = $this->license->createDevice($uid, $label);

		if ($bindIds !== null && $bindIds !== []) {
			$deviceId = (int)($created['device']['id'] ?? 0);
			try {
				if ($deviceId <= 0) {
					throw new \RuntimeException('device_create_missing_id');
				}
				$this->locationAcl->setForSubject(
					LocationAclService::TYPE_DEVICE,
					(string)$deviceId,
					$bindIds,
				);
			} catch (\Throwable $e) {
				// Fail closed: never leave a half-bound scanner that is pairable org-wide.
				if ($deviceId > 0) {
					try {
						$this->license->deactivateDevice($deviceId);
					} finally {
						// purge even if deactivate throws (slot may still have grants).
						$this->locationAcl->purgeDevice($deviceId);
					}
				}
				throw $e;
			}
		}

		return new JSONResponse($created);
	}

	#[NoAdminRequired]
	public function regeneratePairCode(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireAppAdmin($uid);
		return new JSONResponse($this->license->regeneratePairCode($uid, $id));
	}

	#[NoAdminRequired]
	public function removeDevice(int $id): JSONResponse
	{
		$this->access->requireAppAdmin($this->access->currentUserId());
		$this->license->deactivateDevice($id);
		$this->locationAcl->purgeDevice($id);
		return new JSONResponse(['ok' => true]);
	}
}
