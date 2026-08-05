<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Listener;

use OCA\InventoryCheck\Db\LocationFavouriteMapper;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\LicenseService;
use OCA\InventoryCheck\Service\LocationAclService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;

/**
 * GDPR / user-delete: scrub live authorization, seats, favourites, and
 * per-user location ACL grants. Ledger rows stay (audit); only live grants go.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener
{
	public function __construct(
		private AccessControlService $access,
		private LicenseService $license,
		private LocationFavouriteMapper $favourites,
		private LocationAclService $locationAcl,
	) {
	}

	public function handle(Event $event): void
	{
		if (!$event instanceof UserDeletedEvent) {
			return;
		}
		$uid = $event->getUser()->getUID();
		$this->access->purgeUser($uid);
		$this->license->removeSeat($uid);
		$this->favourites->deleteAllForUser($uid);
		$this->locationAcl->purgeUser($uid);
	}
}
