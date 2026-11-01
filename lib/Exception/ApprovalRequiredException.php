<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Exception;

/**
 * F-16 approval gate — an office actor tried to send an order sheet whose
 * total exceeds the configured approval threshold. Still HTTP 403, but the
 * distinct `approval_required` wire code lets clients say "needs app-admin"
 * instead of the generic permission denial.
 */
class ApprovalRequiredException extends PermissionDeniedException
{
	public function __construct()
	{
		parent::__construct('approval_required');
	}
}
