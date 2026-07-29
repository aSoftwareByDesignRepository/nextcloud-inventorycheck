<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\CsvImportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * CSV item import — dry-run then commit (Wave A2). Office-only, enforced
 * inside {@see CsvImportService}. Body accepts either raw CSV text (`csv`
 * field) or a multipart file upload (`file`).
 */
class ImportController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly CsvImportService $import,
		private readonly AccessControlService $access,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function dryRun(): JSONResponse
	{
		return new JSONResponse($this->import->dryRun($this->access->currentUserId(), $this->readCsv()));
	}

	#[NoAdminRequired]
	public function commit(): JSONResponse
	{
		$skip = filter_var($this->request->getParam('skipErrors', '0'), FILTER_VALIDATE_BOOLEAN);
		return new JSONResponse($this->import->commit($this->access->currentUserId(), $this->readCsv(), $skip));
	}

	private function readCsv(): string
	{
		$file = $this->request->getUploadedFile('file');
		if (is_array($file)
			&& ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
			&& is_string($file['tmp_name'] ?? null)
			&& is_uploaded_file($file['tmp_name'])) {
			$content = file_get_contents($file['tmp_name']);
			if (is_string($content) && $content !== '') {
				return $content;
			}
		}
		$csv = $this->request->getParam('csv');
		if (is_string($csv) && $csv !== '') {
			return $csv;
		}
		throw new ValidationException('validation_failed', '', [['field' => 'csv', 'code' => 'empty']]);
	}
}
