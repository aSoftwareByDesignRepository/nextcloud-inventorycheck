<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\Loan;
use OCA\InventoryCheck\Db\LoanMapper;
use OCA\InventoryCheck\Db\NotifyLogMapper;
use OCA\InventoryCheck\Db\PersonMapper;
use OCP\IURLGenerator;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * F2 Wave A: daily loan due scan. Batches of 500, dedupe per
 * loan+recipient+day via iv_notif_log. Overdue is computed on read —
 * never a stored flag (SPEC §8).
 */
final class LoanDueNotifyService
{
	public const SUBJECT = 'loan_due';
	private const BATCH = 500;

	public function __construct(
		private readonly LoanMapper $loans,
		private readonly PersonMapper $persons,
		private readonly ItemMapper $items,
		private readonly NotifyLogMapper $notifyLog,
		private readonly AccessControlService $access,
		private readonly INotificationManager $notificationManager,
		private readonly IURLGenerator $urlGenerator,
		private readonly Clock $clock,
		private readonly LoggerInterface $logger,
	) {
	}

	public function run(): int
	{
		$now = $this->clock->now();
		// Horizon covers everything due up to now (incl. already overdue).
		$loans = $this->loans->dueBetween(0, $now, self::BATCH);
		if ($loans === []) {
			return 0;
		}
		$itemNames = [];
		$personMeta = [];
		$sent = 0;
		foreach ($loans as $loan) {
			$person = $personMeta[$loan->getPersonId()] ??= $this->safePerson($loan->getPersonId());
			$recipients = $this->recipientUids();
			if ($person !== null && $person->getNcUserId() !== null) {
				$recipients[] = $person->getNcUserId();
			}
			foreach (array_unique($recipients) as $uid) {
				$key = sprintf('loandue:%d:%s:%s', (int)$loan->getId(), $uid, gmdate('Ymd', $now));
				try {
					if (!$this->notifyLog->tryInsert($key, $loan->getItemId(), $now)) {
						continue;
					}
					$itemNames[$loan->getItemId()] ??= $this->safeItemName($loan->getItemId());
					$days = (int)floor(($now - (int)$loan->getDueAt()) / 86400);
					$n = $this->notificationManager->createNotification();
					$n->setApp(Application::APP_ID)
						->setUser($uid)
						->setDateTime(new \DateTime('@' . $now))
						->setObject('loan', (string)$loan->getId())
						->setSubject(self::SUBJECT, [
							'loanId' => (string)$loan->getId(),
							'itemName' => $itemNames[$loan->getItemId()],
							'personName' => $person?->getDisplayName() ?? '',
							'qty' => (string)$loan->outstanding(),
							'daysOverdue' => (string)max(0, $days),
						])
						->setLink($this->urlGenerator->linkToRouteAbsolute('inventorycheck.page.item', ['id' => $loan->getItemId()]));
					$this->notificationManager->notify($n);
					$sent++;
				} catch (\Throwable $e) {
					$this->logger->warning('InventoryCheck loan-due notify failed', [
						'app' => Application::APP_ID,
						'loanId' => $loan->getId(),
						'uid' => $uid,
						'message' => $e->getMessage(),
					]);
				}
			}
		}
		return $sent;
	}

	private function safePerson(int $personId): ?\OCA\InventoryCheck\Db\Person
	{
		try {
			return $this->persons->findById($personId);
		} catch (\Throwable) {
			return null;
		}
	}

	private function safeItemName(int $itemId): string
	{
		try {
			return $this->items->findById($itemId)->getName();
		} catch (\Throwable) {
			return '#' . $itemId;
		}
	}

	/** Office recipients only — same policy as the lot-expiry scan (NN-02). */
	private function recipientUids(): array
	{
		return array_merge(
			$this->access->getJsonIdList(AccessControlService::KEY_OFFICE_USER_IDS),
			$this->access->getJsonIdList(AccessControlService::KEY_APP_ADMINS),
		);
	}
}
