<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service\Lookup;

/**
 * One external product data source. Implementations MUST only ever request
 * their own fixed allow-listed endpoint with a validated GTIN — never a URL
 * derived from user input or upstream response content (SSRF boundary, spec NN-01).
 */
interface ProductLookupProvider
{
	/** Stable wire id, e.g. 'openfoodfacts' — also the stats + toggle key. */
	public function id(): string;

	/** Human-facing source name shown next to applied suggestions. */
	public function label(): string;

	/**
	 * Look up a validated GTIN. Never throws for upstream problems —
	 * transport errors, bad status, malformed JSON map to ProviderResult
	 * outcomes; the cascade treats them as "try the next one".
	 */
	public function lookup(string $gtin): ProviderResult;
}
