<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Exception;

/**
 * L2 entry gate failure — user may not open the app at all.
 */
class AppAccessDeniedException extends \Exception
{
	public function __construct(
		private readonly string $denialReason,
	) {
		parent::__construct('app_access_denied');
	}

	public function getDenialReason(): string
	{
		return $this->denialReason;
	}
}
