<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\JournalService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;

/**
 * IV-COMPLIANCE-PACK: journal status + integrity verify — office+ only
 * (NN-04). Read surface; the chain itself is written inside movement
 * transactions, never through an endpoint.
 */
class JournalController extends Controller
{
	private const KEY_LAST_VERIFY = 'journal_last_verify';
	private const KEY_LAST_VERIFY_INTACT = 'journal_last_verify_intact';

	public function __construct(
		IRequest $request,
		private readonly JournalService $journal,
		private readonly AccessControlService $access,
		private readonly IConfig $config,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** Status for the settings card — count + last verify snapshot. */
	#[NoAdminRequired]
	public function status(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireOffice($uid);
		return new JSONResponse([
			'entries' => $this->journal->entryCount(),
			'lastVerifyTs' => (int)$this->config->getAppValue(Application::APP_ID, self::KEY_LAST_VERIFY, '0') ?: null,
			'lastIntact' => $this->config->getAppValue(Application::APP_ID, self::KEY_LAST_VERIFY_INTACT, '') === ''
				? null
				: $this->config->getAppValue(Application::APP_ID, self::KEY_LAST_VERIFY_INTACT, '0') === '1',
		]);
	}

	/** POST variant — the settings button's CSRF-safe verb. */
	#[NoAdminRequired]
	public function verifyPost(): JSONResponse
	{
		return $this->verify();
	}

	/**
	 * Run the integrity walk and persist the verdict snapshot.
	 * Registered for GET + POST — the button uses POST; GET lets an
	 * auditor curl it. Either way the result shape is identical.
	 */
	#[NoAdminRequired]
	public function verify(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireOffice($uid);
		$result = $this->journal->verify();
		$this->config->setAppValue(Application::APP_ID, self::KEY_LAST_VERIFY, (string)time());
		$this->config->setAppValue(Application::APP_ID, self::KEY_LAST_VERIFY_INTACT, $result['intact'] ? '1' : '0');
		return new JSONResponse($result + ['verifiedAt' => time()]);
	}
}
