<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service\Lookup;

/**
 * Expected lookup failure carrying a wire error code + HTTP status.
 * Controllers map it to the standard {error:{code,message,details}} envelope.
 */
final class LookupException extends \RuntimeException
{
	public function __construct(
		public readonly string $errorCode,
		string $message,
		public readonly int $status,
	) {
		parent::__construct($message);
	}
}
