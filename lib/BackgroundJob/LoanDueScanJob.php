<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\BackgroundJob;

use OCA\InventoryCheck\Service\LoanDueNotifyService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/** F2 Wave A: daily scan for due/overdue open loans → deduped notify. */
class LoanDueScanJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly LoanDueNotifyService $notify,
	) {
		parent::__construct($time);
		$this->setInterval(86400);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void
	{
		$this->notify->run();
	}
}
