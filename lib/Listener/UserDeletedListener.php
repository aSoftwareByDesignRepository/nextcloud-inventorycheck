<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Listener;

use OCA\InventoryCheck\Db\LocationFavouriteMapper;
use OCA\InventoryCheck\Db\PersonMapper;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\LicenseService;
use OCA\InventoryCheck\Service\LocationAclService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

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
		private PersonMapper $persons,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void
	{
		if (!$event instanceof UserDeletedEvent) {
			return;
		}
		$uid = $event->getUser()->getUID();
		// This listener fires on EVERY user deletion system-wide — a missing
		// table (disabled-but-enabled app state, mid-upgrade, partial
		// uninstall) must not turn a user deletion into a 500 for an
		// unrelated app. Each purge is isolated so one failure cannot skip
		// the remaining scrubs.
		$purges = [
			fn () => $this->access->purgeUser($uid),
			fn () => $this->license->removeSeat($uid),
			fn () => $this->favourites->deleteAllForUser($uid),
			fn () => $this->locationAcl->purgeUser($uid),
			// F2: person rows keep loan history — only the NC-user link goes.
			fn () => $this->persons->unlinkNcUser($uid),
		];
		foreach ($purges as $purge) {
			try {
				$purge();
			} catch (\Throwable $e) {
				$this->logger->warning('inventorycheck user purge failed', [
					'exception' => $e,
				]);
			}
		}
	}
}
