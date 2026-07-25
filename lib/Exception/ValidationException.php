<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Exception;

/**
 * Input validation failure (HTTP 422).
 *
 * @param list<array{field: string, code: string}> $details
 */
class ValidationException extends \Exception
{
	/**
	 * @param list<array{field: string, code: string}> $details
	 */
	public function __construct(
		private readonly string $errorCode,
		string $message = '',
		private readonly array $details = [],
	) {
		parent::__construct($message !== '' ? $message : $errorCode);
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}

	/**
	 * @return list<array{field: string, code: string}>
	 */
	public function getDetails(): array
	{
		return $this->details;
	}
}
