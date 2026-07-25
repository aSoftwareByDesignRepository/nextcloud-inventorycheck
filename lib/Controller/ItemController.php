<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\Pagination;
use OCA\InventoryCheck\Util\LabelSvg;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

class ItemController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly ItemService $items,
		private readonly AccessControlService $access,
		private readonly IURLGenerator $urlGenerator,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse
	{
		$page = Pagination::parse($this->request->getParam('limit'), $this->request->getParam('offset'));
		$q = trim((string)$this->request->getParam('q', ''));
		$activeRaw = $this->request->getParam('active');
		$active = $activeRaw === null || $activeRaw === '' ? null : filter_var($activeRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
		$lowStock = filter_var($this->request->getParam('lowStock', '0'), FILTER_VALIDATE_BOOLEAN);
		return new JSONResponse($this->items->list($q, $active, $lowStock, $page['limit'], $page['offset']));
	}

	#[NoAdminRequired]
	public function show(int $id): JSONResponse
	{
		return new JSONResponse($this->items->get($id));
	}

	#[NoAdminRequired]
	public function byCode(string $code): JSONResponse
	{
		return new JSONResponse($this->items->byCode(rawurldecode($code)));
	}

	/** SVG download for archival / external printers. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function label(int $id): DataDownloadResponse
	{
		$item = $this->items->get($id);
		$svg = LabelSvg::forItem(
			(string)$item['scanCode'],
			(string)$item['sku'],
			(string)$item['name'],
		);
		return new DataDownloadResponse($svg, 'label-' . $id . '.svg', 'image/svg+xml');
	}

	/** SPEC §7.3 canonical path — same SVG payload as label.svg. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function labelAlias(int $id): DataDownloadResponse
	{
		return $this->label($id);
	}

	/**
	 * Printable label view (UJ-1 / A12) — browser print applies @media print.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function labelPrint(int $id): TemplateResponse
	{
		$item = $this->items->get($id);
		$svg = LabelSvg::forItem(
			(string)$item['scanCode'],
			(string)$item['sku'],
			(string)$item['name'],
		);
		$response = new TemplateResponse(
			Application::APP_ID,
			'label-print',
			[
				'item' => $item,
				'svg' => $svg,
				'backUrl' => $this->urlGenerator->linkToRoute('inventorycheck.page.item', ['id' => $id]),
				'downloadUrl' => $this->urlGenerator->linkToRoute('inventorycheck.item.label', ['id' => $id]),
			],
		);
		$response->renderAs(TemplateResponse::RENDER_AS_BLANK);
		return $response;
	}

	#[NoAdminRequired]
	public function create(): JSONResponse
	{
		return new JSONResponse($this->items->create($this->access->currentUserId(), $this->request->getParams()));
	}

	#[NoAdminRequired]
	public function update(int $id): JSONResponse
	{
		return new JSONResponse($this->items->update($this->access->currentUserId(), $id, $this->request->getParams()));
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse
	{
		$this->items->delete($this->access->currentUserId(), $id);
		return new JSONResponse(['ok' => true]);
	}
}
