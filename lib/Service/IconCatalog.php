<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Support\IconCatalog as SupportIconCatalog;

/**
 * Service-namespace bridge to the canonical icon catalogue.
 *
 * The verbatim-synced shared pack partial
 * (templates/parts/feedback-nav-footer.php, rendered from
 * _shared/app-feedback/feedback-nav-footer.php.tpl) resolves the catalogue
 * as {AppNamespace}\Service\IconCatalog. InventoryCheck keeps the real
 * catalogue in Support — this facade delegates so the shared partial finds
 * it without breaking the verbatim sync.
 */
final class IconCatalog
{
	private function __construct()
	{
	}

	public static function has(string $name): bool
	{
		return in_array($name, SupportIconCatalog::names(), true);
	}

	public static function render(string $name, ?string $extraClass = null): string
	{
		return SupportIconCatalog::render($name, $extraClass);
	}
}
