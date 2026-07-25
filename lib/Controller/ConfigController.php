<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\AccessControlService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class ConfigController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly AccessControlService $access,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		return new JSONResponse([
			'isAppAdmin' => $this->access->isAppAdmin($uid),
			'isOffice' => $this->access->isOffice($uid),
			'allowNegativeStock' => $this->access->allowNegativeStock(),
			'accessRestrictionEnabled' => $this->access->isAccessRestrictionEnabled(),
			'appAdmins' => $this->access->isAppAdmin($uid)
				? $this->access->getJsonIdList(AccessControlService::KEY_APP_ADMINS)
				: [],
			'allowedUsers' => $this->access->isAppAdmin($uid)
				? $this->access->getJsonIdList(AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS)
				: [],
			'allowedGroups' => $this->access->isAppAdmin($uid)
				? $this->access->getJsonIdList(AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS)
				: [],
			'officeUsers' => $this->access->isAppAdmin($uid)
				? $this->access->getJsonIdList(AccessControlService::KEY_OFFICE_USER_IDS)
				: [],
			'officeGroups' => $this->access->isAppAdmin($uid)
				? $this->access->getJsonIdList(AccessControlService::KEY_OFFICE_GROUP_IDS)
				: [],
		]);
	}

	#[NoAdminRequired]
	public function saveAccess(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireAppAdmin($uid);
		$p = $this->request->getParams();
		if (array_key_exists('accessRestrictionEnabled', $p)) {
			$this->access->setAccessRestrictionEnabled((bool)$p['accessRestrictionEnabled']);
		}
		if (isset($p['allowedUsers']) && is_array($p['allowedUsers'])) {
			$this->access->setJsonIdList(AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS, $p['allowedUsers']);
		}
		if (isset($p['allowedGroups']) && is_array($p['allowedGroups'])) {
			$this->access->setJsonIdList(AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS, $p['allowedGroups']);
		}
		if (isset($p['appAdmins']) && is_array($p['appAdmins']) && $this->access->isSystemAdmin($uid)) {
			$this->access->setJsonIdList(AccessControlService::KEY_APP_ADMINS, $p['appAdmins']);
		}
		return $this->index();
	}

	#[NoAdminRequired]
	public function saveOffice(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireAppAdmin($uid);
		$p = $this->request->getParams();
		if (isset($p['officeUsers']) && is_array($p['officeUsers'])) {
			$this->access->setJsonIdList(AccessControlService::KEY_OFFICE_USER_IDS, $p['officeUsers']);
		}
		if (isset($p['officeGroups']) && is_array($p['officeGroups'])) {
			$this->access->setJsonIdList(AccessControlService::KEY_OFFICE_GROUP_IDS, $p['officeGroups']);
		}
		if (array_key_exists('allowNegativeStock', $p)) {
			$this->access->setAllowNegativeStock((bool)$p['allowNegativeStock']);
		}
		return $this->index();
	}
}
