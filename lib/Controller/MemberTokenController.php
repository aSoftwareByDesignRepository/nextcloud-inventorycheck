<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\MemberTokenService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Wave C member-token lifecycle. The raw token is returned ONCE on
 * issue/rotate — after that only hash state exists server-side.
 */
class MemberTokenController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly MemberTokenService $tokens,
		private readonly AccessControlService $access,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** POST /api/persons/{id}/member-token → {token (show once)} */
	#[NoAdminRequired]
	public function issue(int $id): JSONResponse
	{
		$out = $this->tokens->issue($this->access->currentUserId(), $id);
		return new JSONResponse(['token' => $out['token'], 'live' => true], 201);
	}

	/** DELETE /api/persons/{id}/member-token */
	#[NoAdminRequired]
	public function revoke(int $id): JSONResponse
	{
		$this->tokens->revoke($this->access->currentUserId(), $id);
		return new JSONResponse(['live' => false]);
	}

	/** GET /api/persons/{id}/member-token — live state only, never the hash */
	#[NoAdminRequired]
	public function state(int $id): JSONResponse
	{
		$this->access->requireOffice($this->access->currentUserId());
		return new JSONResponse(['live' => $this->tokens->hasLiveToken($id)]);
	}
}
