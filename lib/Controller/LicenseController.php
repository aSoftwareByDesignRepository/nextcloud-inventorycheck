<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\LicenseService;
use OCA\InventoryCheck\Service\LocationAclService;
use OCA\InventoryCheck\Service\Pagination;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class LicenseController extends Controller
{
	use PolicyAuditTrait;

	public function __construct(
		IRequest $request,
		private readonly LicenseService $license,
		private readonly AccessControlService $access,
		private readonly LocationAclService $locationAcl,
		private readonly LoggerInterface $logger,
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
		$status = $this->license->apply($uid, $key);
		// Audit: license applied — never log the key material itself.
		$this->auditPolicyChange($uid, 'license_applied', [
			'seatLimit' => $status['seats']['limit'] ?? null,
			'deviceLimit' => $status['devices']['limit'] ?? null,
		]);
		return new JSONResponse($status);
	}

	#[NoAdminRequired]
	public function remove(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireAppAdmin($uid);
		$status = $this->license->remove();
		$this->auditPolicyChange($uid, 'license_removed');
		return new JSONResponse($status);
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
		$target = $this->request->getParam('uid');
		$result = $this->license->assignSeat($uid, $target);
		$this->auditPolicyChange($uid, 'seat_assigned', ['targetUid' => is_scalar($target) ? (string)$target : null]);
		return new JSONResponse(['ok' => true] + $result + $this->seatSummary());
	}

	#[NoAdminRequired]
	public function removeSeatPost(): JSONResponse
	{
		$actor = $this->access->currentUserId();
		$this->access->requireAppAdmin($actor);
		$target = (string)$this->request->getParam('uid', (string)$this->request->getParam('userId', ''));
		if ($target === '' || $this->license->removeSeat($target) === null) {
			return new JSONResponse(['ok' => false, 'error' => 'seat_not_found'], Http::STATUS_NOT_FOUND);
		}
		$this->auditPolicyChange($actor, 'seat_removed', ['targetUid' => $target]);
		return new JSONResponse(['ok' => true] + $this->seatSummary());
	}

	#[NoAdminRequired]
	public function removeSeat(string $uid): JSONResponse
	{
		$actor = $this->access->currentUserId();
		$this->access->requireAppAdmin($actor);
		if ($this->license->removeSeat($uid) !== null) {
			$this->auditPolicyChange($actor, 'seat_removed', ['targetUid' => $uid]);
		}
		return new JSONResponse(['ok' => true] + $this->seatSummary());
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

		$this->auditPolicyChange($uid, 'device_created', [
			'deviceId' => $created['device']['id'] ?? null,
			'boundLocations' => $bindIds !== null ? count($bindIds) : 0,
		]);
		// Shared pack re-renders the terminal table + slot counter from the
		// mutation response — without a fresh list the new row never paints.
		return new JSONResponse(['ok' => true] + $created + $this->terminalSummary());
	}

	#[NoAdminRequired]
	public function regeneratePairCode(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireAppAdmin($uid);
		$result = $this->license->regeneratePairCode($uid, $id);
		// Audit: pair code rotated — never log the code itself.
		$this->auditPolicyChange($uid, 'device_pair_code_regenerated', ['deviceId' => $id]);
		return new JSONResponse(['ok' => true] + $result);
	}

	#[NoAdminRequired]
	public function removeDevicePost(): JSONResponse
	{
		$actor = $this->access->currentUserId();
		$this->access->requireAppAdmin($actor);
		$id = (int)($this->request->getParam('deviceId') ?? $this->request->getParam('id') ?? 0);
		if ($id <= 0) {
			return new JSONResponse(['ok' => false, 'error' => 'device_not_found'], Http::STATUS_NOT_FOUND);
		}
		try {
			$this->license->deactivateDevice($id);
		} catch (NotFoundException) {
			return new JSONResponse(['ok' => false, 'error' => 'device_not_found'], Http::STATUS_NOT_FOUND);
		}
		$this->locationAcl->purgeDevice($id);
		$this->auditPolicyChange($actor, 'device_removed', ['deviceId' => $id]);
		return new JSONResponse(['ok' => true] + $this->terminalSummary());
	}

	/**
	 * Active-terminal list + slot counters in the shape the shared license-page
	 * pack's applyDeviceMutationResponse()/normalizeDevices() consumes to
	 * re-render the table after a register/revoke mutation.
	 *
	 * @return array{terminals: list<array<string, string>>, terminalDevicesUsed: int, terminalDevicesLimit: int}
	 */
	private function terminalSummary(): array
	{
		$terminals = [];
		foreach (($this->license->listDevices(200, 0)['data'] ?? []) as $row) {
			if (!is_array($row) || empty($row['active'])) {
				continue;
			}
			$createdAt = $row['createdAt'] ?? null;
			$terminals[] = [
				'id' => (string)($row['id'] ?? ''),
				'label' => (string)($row['label'] ?? ''),
				'meta' => (string)($row['state'] ?? ''),
				'registeredAt' => is_int($createdAt) ? date('c', $createdAt) : (string)$createdAt,
			];
		}
		$counts = $this->license->status()['devices'] ?? [];
		return [
			'terminals' => $terminals,
			'terminalDevicesUsed' => (int)($counts['active'] ?? count($terminals)),
			'terminalDevicesLimit' => (int)($counts['limit'] ?? 0),
		];
	}

	/**
	 * Seat list + counters in the shape the shared license-page pack's
	 * applySeatMutationResponse()/normalizeSeats() consumes to re-render the
	 * seat table after an assign/remove mutation.
	 *
	 * @return array{seats: list<array<string, mixed>>, mobileSeatsUsed: int, mobileSeatsLimit: int}
	 */
	private function seatSummary(): array
	{
		$seats = [];
		foreach (($this->license->listSeats(200, 0)['data'] ?? []) as $row) {
			if (!is_array($row)) {
				continue;
			}
			$assignedAt = $row['assignedAt'] ?? '';
			$seats[] = [
				'userId' => (string)($row['uid'] ?? ''),
				'displayName' => (string)($row['displayName'] ?? ($row['uid'] ?? '')),
				'assignedAt' => is_int($assignedAt) ? date('c', $assignedAt) : (string)$assignedAt,
			];
		}
		$counts = $this->license->status()['seats'] ?? [];
		return [
			'seats' => $seats,
			'mobileSeatsUsed' => (int)($counts['assigned'] ?? count($seats)),
			'mobileSeatsLimit' => (int)($counts['limit'] ?? 0),
		];
	}

	#[NoAdminRequired]
	public function removeDevice(int $id): JSONResponse
	{
		$actor = $this->access->currentUserId();
		$this->access->requireAppAdmin($actor);
		$this->license->deactivateDevice($id);
		$this->locationAcl->purgeDevice($id);
		$this->auditPolicyChange($actor, 'device_removed', ['deviceId' => $id]);
		return new JSONResponse(['ok' => true] + $this->terminalSummary());
	}
}
