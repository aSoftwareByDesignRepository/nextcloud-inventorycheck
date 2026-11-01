<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\KitService;
use OCA\InventoryCheck\Service\QtyScale;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;

/** F-17 procedure kits — office manage, app users issue. */
class KitController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly KitService $kits,
		private readonly AccessControlService $access,
		private readonly IConfig $config,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse
	{
		return new JSONResponse(['data' => $this->kits->list($this->access->currentUserId())]);
	}

	#[NoAdminRequired]
	public function create(): JSONResponse
	{
		$p = $this->request->getParams();
		$lines = [];
		foreach ((array)($p['lines'] ?? []) as $l) {
			if (is_array($l)) {
				$lines[] = [
					'itemId' => (int)($l['itemId'] ?? 0),
					'qty' => isset($l['qty']) ? QtyScale::toStorage($this->config, $l['qty']) : 0,
				];
			}
		}
		$id = $this->kits->create(
			$this->access->currentUserId(),
			(string)($p['name'] ?? ''),
			isset($p['note']) ? (string)$p['note'] : null,
			$lines,
		);
		return new JSONResponse(['id' => $id], 201);
	}

	#[NoAdminRequired]
	public function issue(int $id): JSONResponse
	{
		$p = $this->request->getParams();
		$this->kits->issue(
			$this->access->currentUserId(),
			$id,
			(int)($p['locationId'] ?? 0),
			isset($p['reason']) ? (string)$p['reason'] : null,
		);
		return new JSONResponse(['ok' => true]);
	}
}
