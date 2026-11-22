<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Db\LotMapper;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\CodeRules;
use OCA\InventoryCheck\Service\LotRecallService;
use OCA\InventoryCheck\Service\LotService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * F-07 lot registry + F-23 recall lookup.
 * Office+ expiry correction is the ONLY overwrite path (NN-10) and is audited.
 */
class LotController extends Controller
{
	use PolicyAuditTrait;

	public function __construct(
		IRequest $request,
		private readonly LotMapper $lots,
		private readonly LotRecallService $recall,
		private readonly LotService $lotService,
		private readonly AccessControlService $access,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** Lots for one item (?itemId=) — qty>0 filter client-side via balances. */
	#[NoAdminRequired]
	public function index(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireOffice($uid);
		$itemId = (int)$this->request->getParam('itemId', 0);
		if ($itemId <= 0) {
			throw new ValidationException('validation_failed', '', [['field' => 'itemId', 'code' => 'validation_failed']]);
		}
		return new JSONResponse(['data' => array_map(static fn ($l) => $l->toApi(), $this->lots->findByItem($itemId))]);
	}

	/** F-23 recall: code → every item/location carrying that lot. */
	#[NoAdminRequired]
	public function byCode(string $code): JSONResponse
	{
		return new JSONResponse($this->recall->lookup(
			$this->access->currentUserId(),
			CodeRules::trim(rawurldecode($code)),
		));
	}

	/**
	 * IV-LOT-EXPIRY-PACK: FEFO picker payload — lots with remaining qty +
	 * state, server-sorted. App-access (field users see expiry info on the
	 * lots they can access — AC-11); qty scoped to actor-visible locations.
	 */
	#[NoAdminRequired]
	public function itemLots(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$locRaw = $this->request->getParam('locationId');
		$locationId = $locRaw !== null && $locRaw !== '' ? (int)$locRaw : null;
		return new JSONResponse([
			'data' => $this->lotService->lotsWithStock($uid, $id, $locationId),
		]);
	}

	/** Watch card rows — in-stock lots inside the warn window (office scope). */
	#[NoAdminRequired]
	public function expiring(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$limit = min(max((int)$this->request->getParam('limit', 20), 1), 100);
		return new JSONResponse([
			'data' => $this->lotService->expiringSoon($uid, $limit),
		]);
	}

	/** Office+ expiry correction — the only overwrite path (audit-logged). */
	#[NoAdminRequired]
	public function setExpiry(int $id): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireOffice($uid);
		$raw = $this->request->getParam('expiresAt');
		$expiresAt = null;
		if ($raw !== null && $raw !== '') {
			if (!is_int($raw) && !(is_string($raw) && preg_match('/^\d+$/', $raw) === 1)) {
				throw new ValidationException('validation_failed', '', [['field' => 'expiresAt', 'code' => 'validation_failed']]);
			}
			$expiresAt = (int)$raw;
			if ($expiresAt <= 0 || $expiresAt > 4_102_444_800) {
				throw new ValidationException('validation_failed', '', [['field' => 'expiresAt', 'code' => 'validation_failed']]);
			}
		}
		$lot = $this->lots->findById($id);
		$before = $lot->getExpiresAt();
		$this->lots->setExpiry($id, $expiresAt);
		$this->auditPolicyChange($uid, 'lot_expiry_corrected', [
			'lotId' => $id,
			'itemId' => $lot->getItemId(),
			'before' => $before,
			'after' => $expiresAt,
		]);
		return new JSONResponse($this->lots->findById($id)->toApi());
	}
}
