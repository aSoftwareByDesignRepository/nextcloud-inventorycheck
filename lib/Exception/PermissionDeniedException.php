<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Exception;

/**
 * L3 role failure — maps to HTTP 403 `permission_denied` (SPEC §7.2).
 */
class PermissionDeniedException extends \Exception
{
	public function __construct(string $message = 'permission_denied')
	{
		parent::__construct($message);
	}
}
