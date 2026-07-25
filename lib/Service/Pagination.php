<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Exception\ValidationException;

/** SPEC S9 pagination helpers. */
final class Pagination
{
	public const DEFAULT_LIMIT = 50;
	public const MAX_LIMIT = 200;

	/**
	 * @return array{limit: int, offset: int}
	 */
	public static function parse(mixed $limit, mixed $offset): array
	{
		$lim = $limit === null || $limit === '' ? self::DEFAULT_LIMIT : (is_numeric($limit) ? (int)$limit : -1);
		$off = $offset === null || $offset === '' ? 0 : (is_numeric($offset) ? (int)$offset : -1);
		if ($lim < 1 || $lim > self::MAX_LIMIT || $off < 0) {
			throw new ValidationException('invalid_query');
		}
		return ['limit' => $lim, 'offset' => $off];
	}
}
