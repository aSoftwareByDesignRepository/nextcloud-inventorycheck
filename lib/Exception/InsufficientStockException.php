<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Exception;

/**
 * Convenience subclass of ConflictException for insufficient_stock (409).
 * Carries available qty for localised toast text.
 */
class InsufficientStockException extends ConflictException
{
	public function __construct(
		private readonly int $availableQty,
		private readonly string $locationLabel = '',
	) {
		parent::__construct('insufficient_stock');
	}

	public function getAvailableQty(): int
	{
		return $this->availableQty;
	}

	public function getLocationLabel(): string
	{
		return $this->locationLabel;
	}
}
