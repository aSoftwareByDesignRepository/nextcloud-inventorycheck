<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\FlangeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Soft flanges to sibling Check apps (Wave B2 / C5). Status/settings are
 * app-admin only; the maintenance-issue action is office-only (enforced
 * inside {@see FlangeService}).
 *
 * Line qty stays in display units here; {@see \OCA\InventoryCheck\Public\StockIssueFacade}
 * owns display→storage conversion (C1).
 */
class FlangeController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly FlangeService $flange,
		private readonly AccessControlService $access,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function status(): JSONResponse
	{
		$this->access->requireAppAdmin($this->access->currentUserId());
		return new JSONResponse($this->flange->status());
	}

	#[NoAdminRequired]
	public function issueMaintWo(): JSONResponse
	{
		$p = $this->request->getParams();
		$lines = $this->normalizeLines(is_array($p['lines'] ?? null) ? $p['lines'] : []);
		return new JSONResponse($this->flange->issueForMaintWo(
			$this->access->currentUserId(),
			(int)($p['woId'] ?? 0),
			$lines,
			isset($p['locationId']) && $p['locationId'] !== '' ? (int)$p['locationId'] : null,
		));
	}

	#[NoAdminRequired]
	public function issueProject(): JSONResponse
	{
		$p = $this->request->getParams();
		$lines = $this->normalizeLines(is_array($p['lines'] ?? null) ? $p['lines'] : []);
		return new JSONResponse($this->flange->issueForProject(
			$this->access->currentUserId(),
			(int)($p['projectId'] ?? 0),
			$lines,
			isset($p['locationId']) && $p['locationId'] !== '' ? (int)$p['locationId'] : null,
		));
	}

	#[NoAdminRequired]
	public function saveSettings(): JSONResponse
	{
		$this->access->requireAppAdmin($this->access->currentUserId());
		$p = $this->request->getParams();
		if (array_key_exists('maintFlangeEnabled', $p)) {
			$this->flange->setMaintEnabled(filter_var($p['maintFlangeEnabled'], FILTER_VALIDATE_BOOLEAN));
		}
		if (array_key_exists('projectFlangeEnabled', $p)) {
			$this->flange->setProjectEnabled(filter_var($p['projectFlangeEnabled'], FILTER_VALIDATE_BOOLEAN));
		}
		if (array_key_exists('defaultIssueLocationId', $p)) {
			$raw = $p['defaultIssueLocationId'];
			$this->flange->setDefaultLocationId($raw === null || $raw === '' ? null : (int)$raw);
		}
		return new JSONResponse($this->flange->status());
	}

	/**
	 * @param list<mixed> $lines
	 * @return list<array{sku: string, qty: mixed}>
	 */
	private function normalizeLines(array $lines): array
	{
		$out = [];
		foreach ($lines as $line) {
			if (!is_array($line)) {
				continue;
			}
			$sku = (string)($line['sku'] ?? '');
			if ($sku === '' || !array_key_exists('qty', $line)) {
				continue;
			}
			$out[] = [
				'sku' => $sku,
				// Display qty — StockIssueFacade owns display→storage (C1).
				'qty' => $line['qty'],
			];
		}
		return $out;
	}
}
