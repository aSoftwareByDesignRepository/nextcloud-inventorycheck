<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Public;

/**
 * @psalm-immutable
 */
final class FacadeResult
{
	/**
	 * @param array<string, mixed>|null $data
	 */
	public function __construct(
		public readonly bool $ok,
		public readonly ?string $code = null,
		public readonly ?string $message = null,
		public readonly ?array $data = null,
	) {
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function success(array $data, ?string $code = null): self
	{
		return new self(true, $code, null, $data);
	}

	/**
	 * @param array<string, mixed>|null $data
	 */
	public static function failure(string $code, ?string $message = null, ?array $data = null): self
	{
		return new self(false, $code, $message, $data);
	}
}
