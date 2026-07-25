<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCP\DB\Exception as DBException;

/**
 * Backstop detection for unique-index races: when two writers pass the
 * application-level uniqueness checks simultaneously, the DB unique index
 * is the last line of defence and must surface as 409 code_exists, not 500.
 */
final class UniqueViolation
{
	public static function is(\Throwable $e): bool
	{
		return $e instanceof DBException
			&& $e->getReason() === DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION;
	}
}
