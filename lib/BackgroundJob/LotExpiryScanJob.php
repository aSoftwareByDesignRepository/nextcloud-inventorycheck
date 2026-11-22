<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\BackgroundJob;

use OCA\InventoryCheck\Service\LotExpiryNotifyService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/** IV-LOT-EXPIRY-PACK: daily scan for lots entering the warn window. */
class LotExpiryScanJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly LotExpiryNotifyService $notify,
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
