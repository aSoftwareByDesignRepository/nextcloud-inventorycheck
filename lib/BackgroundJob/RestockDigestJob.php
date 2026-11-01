<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\BackgroundJob;

use OCA\InventoryCheck\Service\RestockDigestService;
use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;

/** F-08 weekly reorder digest (checks daily, fires when interval elapsed). */
class RestockDigestJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly RestockDigestService $digest,
	) {
		parent::__construct($time);
		$this->setInterval(86400);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void
	{
		$this->digest->run();
	}
}
