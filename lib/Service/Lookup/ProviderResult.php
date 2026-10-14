<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service\Lookup;

/**
 * Outcome of one provider attempt — drives stats, logging, and the
 * "did anything answer cleanly?" decision in the cascade.
 */
final class ProviderResult
{
	public const HIT = 'hit';
	public const MISS = 'miss';
	public const ERROR = 'error';
	public const RATE_LIMITED = 'rate_limited';

	private function __construct(
		public readonly string $outcome,
		public readonly ?ProductSuggestion $suggestion,
	) {
	}

	public static function hit(ProductSuggestion $suggestion): self
	{
		return new self(self::HIT, $suggestion);
	}

	public static function miss(): self
	{
		return new self(self::MISS, null);
	}

	public static function error(): self
	{
		return new self(self::ERROR, null);
	}

	public static function rateLimited(): self
	{
		return new self(self::RATE_LIMITED, null);
	}
}
