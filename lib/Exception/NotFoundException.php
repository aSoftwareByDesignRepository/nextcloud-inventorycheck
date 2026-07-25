<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Exception;

/**
 * Unknown entity / by-code miss. Maps to HTTP 404.
 * Pass `code_not_found` for scan/SKU misses (S8).
 * Pass `unknown_item` / `unknown_location` for missing masters (§4.1 / §7.2).
 */
class NotFoundException extends \Exception
{
	public function __construct(
		private readonly string $errorCode = 'not_found',
	) {
		parent::__construct($errorCode);
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}
}
