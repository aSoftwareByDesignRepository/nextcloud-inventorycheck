<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Exception;

/**
 * State conflict (HTTP 409). Stable machine-readable `errorCode` from SPEC §7.2.
 */
class ConflictException extends \Exception
{
	public function __construct(
		private readonly string $errorCode,
		string $message = '',
	) {
		parent::__construct($message !== '' ? $message : $errorCode);
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}
}
