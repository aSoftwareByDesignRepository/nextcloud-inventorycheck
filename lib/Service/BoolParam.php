<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Exception\ValidationException;

/**
 * Strict 0/1 boolean parse. Never use bare `(bool)$value` — in PHP `(bool)'false'` is true.
 */
final class BoolParam
{
	public static function parse(mixed $value, string $field): bool
	{
		if (is_bool($value)) {
			return $value;
		}
		if ($value === 1 || $value === '1') {
			return true;
		}
		if ($value === 0 || $value === '0') {
			return false;
		}
		throw new ValidationException('validation_failed', $field . ' must be a boolean.', [
			['field' => $field, 'code' => 'invalid_type'],
		]);
	}
}
