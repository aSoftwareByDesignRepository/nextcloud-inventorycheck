<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Db\LocationMapper;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Public\StockIssueFacade;
use OCA\InventoryCheck\Public\StockIssueRequest;
use OCP\App\IAppManager;
use OCP\IConfig;

/**
 * Soft flanges to sibling Check apps (Wave B2 / C5).
 *
 * Failures never invent peer-app state; callers map insufficient_stock → sync failed.
 * Stock posts go through {@see StockIssueFacade} (FC-IV-ISSUE).
 */
class FlangeService
{
	public const KEY_MAINT_ENABLED = 'maint_flange_enabled';
	public const KEY_PROJECT_ENABLED = 'project_flange_enabled';
	public const KEY_DEFAULT_ISSUE_LOCATION = 'flange_default_location_id';
	public const REF_MAINT_WO = 'maint_wo';
	public const REF_PROJECT = 'project';

	public function __construct(
		private readonly IConfig $config,
		private readonly IAppManager $appManager,
		private readonly LocationMapper $locations,
		private readonly AccessControlService $access,
		private readonly StockIssueFacade $stockIssue,
	) {
	}

	/** @return array<string, mixed> */
	public function status(): array
	{
		return [
			'maintenanceCheckEnabled' => $this->appManager->isEnabledForUser('maintenancecheck'),
			'maintFlangeEnabled' => $this->isMaintEnabled(),
			'projectCheckEnabled' => $this->appManager->isEnabledForUser('projectcheck'),
			'projectFlangeEnabled' => $this->isProjectEnabled(),
			'defaultIssueLocationId' => $this->defaultLocationId(),
		];
	}

	public function setMaintEnabled(bool $enabled): void
	{
		$this->config->setAppValue(Application::APP_ID, self::KEY_MAINT_ENABLED, $enabled ? '1' : '0');
	}

	public function setProjectEnabled(bool $enabled): void
	{
		$this->config->setAppValue(Application::APP_ID, self::KEY_PROJECT_ENABLED, $enabled ? '1' : '0');
	}

	public function setDefaultLocationId(?int $locationId): void
	{
		if ($locationId === null || $locationId <= 0) {
			$this->config->deleteAppValue(Application::APP_ID, self::KEY_DEFAULT_ISSUE_LOCATION);
			return;
		}
		$loc = $this->locations->findById($locationId);
		if (!$loc->getActive()) {
			throw new ValidationException('inactive_location');
		}
		$this->config->setAppValue(Application::APP_ID, self::KEY_DEFAULT_ISSUE_LOCATION, (string)$locationId);
	}

	/**
	 * @param list<array{sku: string, qty: int}> $lines
	 * @return array{ok: bool, inventory_sync: string, movements: list<array<string, mixed>>, errors: list<array{sku: string, code: string}>}
	 */
	public function issueForMaintWo(string $actorUid, int $woId, array $lines, ?int $locationId = null): array
	{
		$this->access->requireOffice($actorUid);
		if (!$this->isMaintEnabled()) {
			return ['ok' => false, 'inventory_sync' => 'disabled', 'movements' => [], 'errors' => [
				['sku' => '', 'code' => 'flange_disabled'],
			]];
		}
		if (!$this->appManager->isEnabledForUser('maintenancecheck')) {
			return ['ok' => false, 'inventory_sync' => 'peer_absent', 'movements' => [], 'errors' => [
				['sku' => '', 'code' => 'peer_absent'],
			]];
		}
		if ($woId <= 0) {
			throw new ValidationException('validation_failed', '', [['field' => 'woId', 'code' => 'validation_failed']]);
		}
		return $this->runIssue($actorUid, $lines, StockIssueRequest::REF_MAINT_WO, $woId, $locationId, self::REF_MAINT_WO);
	}

	/**
	 * @param list<array{sku: string, qty: int}> $lines
	 * @return array{ok: bool, inventory_sync: string, movements: list<array<string, mixed>>, errors: list<array{sku: string, code: string}>}
	 */
	public function issueForProject(string $actorUid, int $projectId, array $lines, ?int $locationId = null): array
	{
		$this->access->requireOffice($actorUid);
		if (!$this->isProjectEnabled()) {
			return ['ok' => false, 'inventory_sync' => 'disabled', 'movements' => [], 'errors' => [
				['sku' => '', 'code' => 'flange_disabled'],
			]];
		}
		if (!$this->appManager->isEnabledForUser('projectcheck')) {
			return ['ok' => false, 'inventory_sync' => 'peer_absent', 'movements' => [], 'errors' => [
				['sku' => '', 'code' => 'peer_absent'],
			]];
		}
		if ($projectId <= 0) {
			throw new ValidationException('validation_failed', '', [['field' => 'projectId', 'code' => 'validation_failed']]);
		}
		return $this->runIssue($actorUid, $lines, StockIssueRequest::REF_PROJECT, $projectId, $locationId, self::REF_PROJECT);
	}

	/**
	 * @param list<array{sku: string, qty: int}> $lines
	 * @return array{ok: bool, inventory_sync: string, movements: list<array<string, mixed>>, errors: list<array{sku: string, code: string}>}
	 */
	private function runIssue(
		string $actorUid,
		array $lines,
		string $refType,
		int $refId,
		?int $locationId,
		string $apiRefType,
	): array {
		$result = $this->stockIssue->issueBySkuBundle(new StockIssueRequest(
			actorUid: $actorUid,
			lines: $lines,
			locationPolicy: StockIssueRequest::POLICY_EQUIPMENT_DEFAULT,
			refType: $refType,
			refId: $refId,
			locationId: $locationId,
		));

		if ($result->ok) {
			$movements = [];
			foreach (($result->data['movements'] ?? []) as $row) {
				$movements[] = [
					'id' => (int)($row['movementId'] ?? 0),
					'sku' => (string)($row['sku'] ?? ''),
					'qty' => $row['qty'] ?? 0,
					'locationId' => (int)($row['locationId'] ?? 0),
					'refType' => $apiRefType,
					'refId' => $refId,
				];
			}
			return [
				'ok' => true,
				'inventory_sync' => 'ok',
				'movements' => $movements,
				'errors' => [],
			];
		}

		$code = $result->code ?? 'inventory_sync_failed';
		$sku = (string)(($result->data['sku'] ?? '') ?: '');
		return [
			'ok' => false,
			'inventory_sync' => 'failed',
			'movements' => [],
			'errors' => [['sku' => $sku, 'code' => $code]],
		];
	}

	private function isMaintEnabled(): bool
	{
		return $this->config->getAppValue(Application::APP_ID, self::KEY_MAINT_ENABLED, '0') === '1';
	}

	private function isProjectEnabled(): bool
	{
		return $this->config->getAppValue(Application::APP_ID, self::KEY_PROJECT_ENABLED, '0') === '1';
	}

	private function defaultLocationId(): ?int
	{
		$raw = $this->config->getAppValue(Application::APP_ID, self::KEY_DEFAULT_ISSUE_LOCATION, '');
		if ($raw === '' || !ctype_digit($raw)) {
			return null;
		}
		return (int)$raw;
	}
}
