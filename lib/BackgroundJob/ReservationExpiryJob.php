<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\BackgroundJob;

use OCA\InventoryCheck\Service\ReservationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/** Wave C: hourly sweep — stale reservations transition to 'expired' (CAS-idempotent). */
class ReservationExpiryJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly ReservationService $reservations,
	) {
		parent::__construct($time);
		$this->setInterval(3600);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void
	{
		$this->reservations->sweepExpired();
	}
}
