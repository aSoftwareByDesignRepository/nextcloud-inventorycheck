<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

/** Thin clock for testability. */
class Clock
{
	public function now(): int
	{
		return time();
	}

	public function todayYmd(): string
	{
		return gmdate('Y-m-d');
	}
}
