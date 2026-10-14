<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service\Lookup;

/**
 * Normalized suggestion returned by a {@see ProductLookupProvider}.
 *
 * All string fields are untrusted third-party content — the service layer
 * sanitizes/truncates them before they reach the wire. Providers populate
 * raw values only; never trust them for markup or URLs.
 */
final class ProductSuggestion
{
	public function __construct(
		public readonly string $name,
		public readonly ?string $brand = null,
		public readonly ?string $description = null,
		public readonly ?string $sourceUrl = null,
	) {
	}
}
