<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Middleware;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Exception\AppAccessDeniedException;
use OCA\InventoryCheck\Exception\ConflictException;
use OCA\InventoryCheck\Exception\InsufficientStockException;
use OCA\InventoryCheck\Exception\MobileGateException;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\PermissionDeniedException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\QtyScale;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Middleware;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;

class AppAccessMiddleware extends Middleware
{
	private const HTTP_PAYMENT_REQUIRED = 402;

	public function __construct(
		private readonly IUserSession $userSession,
		private readonly AccessControlService $accessControl,
		private readonly IRequest $request,
		private readonly IURLGenerator $urlGenerator,
		private readonly IFactory $l10nFactory,
		private readonly IConfig $config,
	) {
	}

	public function beforeController($controller, $methodName): void
	{
		$class = is_object($controller) ? get_class($controller) : '';
		if (!str_starts_with($class, 'OCA\\InventoryCheck\\Controller\\')) {
			return;
		}
		// Mobile API authenticates via session OR X-IV-Device-Token inside the
		// controller; L2 canUseApp only applies to logged-in browser sessions.
		if (str_contains($class, 'MobileController')) {
			return;
		}
		$user = $this->userSession->getUser();
		if ($user === null) {
			return;
		}
		if ($this->accessControl->canUseApp($user->getUID())) {
			return;
		}
		throw new AppAccessDeniedException(
			$this->accessControl->denialReasonWhenCannotUseApp($user->getUID()),
		);
	}

	public function afterException($controller, $methodName, \Exception $exception)
	{
		$class = is_object($controller) ? get_class($controller) : '';
		if (!str_starts_with($class, 'OCA\\InventoryCheck\\Controller\\')) {
			throw $exception;
		}

		$l = $this->l10nFactory->get(Application::APP_ID);

		if ($exception instanceof AppAccessDeniedException) {
			return $this->accessDeniedResponse($exception, $l);
		}
		if ($exception instanceof PermissionDeniedException) {
			return $this->envelope('permission_denied', $l->t('You do not have permission for this action.'), Http::STATUS_FORBIDDEN);
		}
		if ($exception instanceof NotFoundException) {
			$code = $exception->getErrorCode();
			$msg = match ($code) {
				'code_not_found' => $l->t('No active item matches this code.'),
				'unknown_item' => $l->t('This item does not exist.'),
				'unknown_location' => $l->t('This location does not exist.'),
				'photo_not_found' => $l->t('No photo for this item.'),
				default => $l->t('The requested entry does not exist.'),
			};
			return $this->envelope($code, $msg, Http::STATUS_NOT_FOUND);
		}
		if ($exception instanceof InsufficientStockException) {
			// Use plain %s placeholders — IL10N::t() does not accept a plural count
			// (that is n()). Passing qty as %n previously rendered the default
			// plural form (count=1) and shoved the qty into the location slot.
			// Wave C1: show display qty (not milli storage ints).
			$qty = (string)QtyScale::toDisplay($this->config, $exception->getAvailableQty());
			$msg = $exception->getLocationLabel() !== ''
				? $l->t('Only %s left in %s.', [$qty, $exception->getLocationLabel()])
				: $l->t('Not enough stock at this location (available: %s).', [$qty]);
			return $this->envelope('insufficient_stock', $msg, Http::STATUS_CONFLICT);
		}
		if ($exception instanceof ConflictException) {
			$code = $exception->getErrorCode();
			// item_lock_conflict is a transient lock/retry signal (track_mode flip
			// mid-movement). 423 lets companions treat it like ApiError.isLocked.
			$status = $code === 'item_lock_conflict'
				? Http::STATUS_LOCKED
				: Http::STATUS_CONFLICT;
			return $this->envelope(
				$code,
				$this->conflictMessage($code, $l),
				$status,
			);
		}
		if ($exception instanceof ValidationException) {
			return new JSONResponse([
				'error' => [
					'code' => $exception->getErrorCode(),
					'message' => $this->validationMessage($exception, $l),
					'details' => $exception->getDetails(),
				],
			], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		if ($exception instanceof MobileGateException) {
			// AF-IV20: 402 license/seat/device envelopes are mobile-only.
			// Web session controllers must never surface payment-required for
			// MobileGateException — rethrow so it becomes a server error, not 402.
			if (!str_contains($class, 'MobileController')) {
				throw $exception;
			}
			// SPEC §9.1 rung 1 / AC-17: unauthenticated mobile callers are 401,
			// never 402 (402 is reserved for license/seat/device entitlement misses).
			if ($exception->getErrorCode() === 'auth_required') {
				return $this->envelope(
					'auth_required',
					$l->t('Authentication required.'),
					Http::STATUS_UNAUTHORIZED,
				);
			}
			if ($exception->getErrorCode() === 'rate_limited') {
				return $this->envelope(
					'rate_limited',
					$l->t('Too many pairing attempts. Try again later.'),
					429,
				);
			}
			return $this->envelope(
				$exception->getErrorCode(),
				$this->gateMessage($exception->getErrorCode(), $l),
				self::HTTP_PAYMENT_REQUIRED,
			);
		}

		throw $exception;
	}

	private function isJsonRoute(): bool
	{
		$path = (string)($this->request->getPathInfo() ?? '');
		return str_contains($path, '/api/')
			|| str_contains($path, '/mobile/')
			|| $this->request->getMethod() !== 'GET';
	}

	private function envelope(string $code, string $message, int $status): JSONResponse
	{
		// SPEC §7.1: details is always present (empty when not applicable).
		return new JSONResponse(['error' => ['code' => $code, 'message' => $message, 'details' => []]], $status);
	}

	private function accessDeniedResponse(AppAccessDeniedException $exception, IL10N $l): JSONResponse|TemplateResponse
	{
		if ($this->isJsonRoute()) {
			return $this->envelope(
				'app_access_denied',
				$l->t('You are not allowed to use InventoryCheck.'),
				Http::STATUS_FORBIDDEN,
			);
		}

		[$message, $hint] = match ($exception->getDenialReason()) {
			AccessControlService::DENIAL_RESTRICTION => [
				$l->t('Your organisation restricts InventoryCheck access. You are not on the allow-list.'),
				$l->t('Ask a Nextcloud or InventoryCheck administrator to add you in Settings → Access.'),
			],
			default => [
				$l->t('You are not allowed to use InventoryCheck right now.'),
				$l->t('If you believe this is a mistake, contact your InventoryCheck administrator.'),
			],
		};
		$response = new TemplateResponse(
			Application::APP_ID,
			'access-denied',
			[
				'message' => $message,
				'hint' => $hint,
				'homeUrl' => $this->urlGenerator->linkToDefaultPageUrl(),
			],
		);
		$response->setStatus(Http::STATUS_FORBIDDEN);
		$response->renderAs(TemplateResponse::RENDER_AS_USER);
		return $response;
	}

	private function conflictMessage(string $code, IL10N $l): string
	{
		return match ($code) {
			'insufficient_stock' => $l->t('Not enough stock at this location.'),
			'code_exists' => $l->t('This code is already in use.'),
			'item_has_stock' => $l->t('This item still has stock. Move or adjust it to zero before deactivating.'),
			'track_mode_requires_zero_stock' => $l->t('Clear stock to zero before switching this item to lot or serial tracking.'),
			'location_has_stock' => $l->t('This location still has stock. Move or adjust it to zero before deactivating.'),
			'item_has_movements' => $l->t('This item has movement history and cannot be deleted. Deactivate it instead.'),
			'location_has_movements' => $l->t('This location has movement history and cannot be deleted. Deactivate it instead.'),
			'item_in_open_stocktake' => $l->t('This item is on an open stocktake. Close or finish that stocktake first.'),
			'location_in_open_stocktake' => $l->t('This location has an open stocktake. Close or finish that stocktake first.'),
			'seat_limit_reached' => $l->t('All licensed seats are assigned. Remove a seat or upgrade the license.'),
			'device_limit_reached' => $l->t('All licensed device slots are used. Remove a device or upgrade the license.'),
			'campaign_not_open' => $l->t('This cycle count has already been started or closed.'),
			'campaign_not_counting' => $l->t('This cycle count is not open for counting right now.'),
			'line_already_posted' => $l->t('This line was already posted and cannot be counted again.'),
			'item_lock_conflict' => $l->t('This item changed while you were working. Reload and try again.'),
			default => $l->t('The action conflicts with the current state. Reload and try again.'),
		};
	}

	private function validationMessage(ValidationException $exception, IL10N $l): string
	{
		return match ($exception->getErrorCode()) {
			'invalid_qty' => $l->t('The quantity is not valid.'),
			'qty_out_of_range' => $l->t('The resulting stock would be out of the allowed range.'),
			'same_location' => $l->t('Transfer source and destination must be different.'),
			'inactive_item' => $l->t('This item is deactivated.'),
			'inactive_location' => $l->t('This location is deactivated.'),
			'invalid_code_format' => $l->t('The code format is not valid. Use letters, digits, and . _ / - only.'),
			'invalid_query' => $l->t('The list parameters are not valid.'),
			'license_invalid' => $l->t('This license key is not valid: %s', [$exception->getMessage()]),
			'unknown_user' => $l->t('This Nextcloud user does not exist.'),
			'unknown_group' => $l->t('This Nextcloud group does not exist.'),
			'unknown_device' => $l->t('This scanner device slot does not exist.'),
			'access_allowlist_required' => $l->t('Turn on access restriction only after choosing at least one allowed user or group.'),
			'invalid_pair_code' => $l->t('This pairing code is invalid or expired.'),
			'photo_too_large' => $l->t('The photo is too large. Maximum size is 2 MB.'),
			'photo_type_invalid' => $l->t('Only JPEG, PNG, or WebP photos are allowed.'),
			'upload_failed' => $l->t('The upload failed. Please try again.'),
			'count_incomplete' => $l->t('Every line must be counted before closing, or choose to abandon uncounted lines.'),
			'count_conflict' => $l->t('Stock changed after this stocktake started. Review conflict lines, then close again and confirm you accept the counted quantities.'),
			'track_mode_changed' => $l->t('An item on this stocktake was switched to lot or serial tracking. Remove it from the count or set tracking back to none, then try again.'),
			'stocktake_too_large' => $l->t('Too many active items to include in one stocktake. Deactivate unused items or split the count, then try again.'),
			'favourite_limit' => $l->t('You already have the maximum number of favourite locations.'),
			'location_code_mismatch' => $l->t('The scanned location code does not match the selected location.'),
			default => $l->t('Please check the highlighted fields.'),
		};
	}

	private function gateMessage(string $code, IL10N $l): string
	{
		return match ($code) {
			'auth_required' => $l->t('Authentication required.'),
			'license_missing' => $l->t('No mobile license is stored on this server.'),
			'license_expired' => $l->t('The mobile license has expired.'),
			'seat_required' => $l->t('You do not have a mobile seat assigned.'),
			'device_required' => $l->t('This device is not paired or was deactivated.'),
			'device_limit_exceeded' => $l->t('This device is above the licensed device limit.'),
			default => $l->t('Your mobile seat is above the licensed limit.'),
		};
	}
}
