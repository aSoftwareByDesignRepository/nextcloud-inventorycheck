<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Support;

/**
 * Inline SVG icon catalogue for InventoryCheck templates (Check-family
 * convention, README §2a / MobilityCheck pattern).
 *
 * Lucide-style 24x24 stroke icons inheriting `currentColor` so they recolour
 * with any Nextcloud theme. Output is static markup with no user data and
 * always carries `aria-hidden="true"` / `focusable="false"`.
 */
final class IconCatalog
{
	/** @var array<string, string> Inner SVG markup by icon name. */
	private const ICONS = [
		// Navigation
		'layout-grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
		'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
		'wrench' => '<path d="M14.7 6.3a4 4 0 0 0-5.5 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-3 3-2.4-.6-.6-2.4Z"/>',
		'calendar-check' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><path d="m9 16 2 2 4-4"/>',
		'calendar' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
		'list-checks' => '<path d="m3 6 2 2 4-4"/><path d="m3 14 2 2 4-4"/><path d="M13 7h8"/><path d="M13 15h8"/><path d="M13 19h6"/>',
		'clipboard-list' => '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M9 4H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-3"/><path d="M9 12h.01M9 16h.01M12.5 12H16M12.5 16H16"/>',
		'settings' => '<path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 0 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 0 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 0 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 0 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 0 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3 1.7 1.7 0 0 0 1-1.5V3a2 2 0 0 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 0 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8 1.7 1.7 0 0 0 1.5 1H21a2 2 0 0 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/>',
		'building' => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M9 7h.01M15 7h.01M9 11h.01M15 11h.01M9 15h.01M15 15h.01M10 21v-4h4v4"/>',
		// Actions & status
		'plus' => '<path d="M12 5v14M5 12h14"/>',
		'check' => '<path d="m5 12 5 5L20 7"/>',
		'check-circle' => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
		'x' => '<path d="M18 6 6 18M6 6l12 12"/>',
		'x-circle' => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6M9 9l6 6"/>',
		'edit' => '<path d="M11 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-5"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4Z"/>',
		'trash' => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
		'search' => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
		'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		'history' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l3 2"/>',
		'skip' => '<path d="m5 4 10 8-10 8V4Z"/><path d="M19 5v14"/>',
		'rotate' => '<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/>',
		'arrow-left' => '<path d="M19 12H5M11 5l-7 7 7 7"/>',
		'arrow-right' => '<path d="M5 12h14M13 5l7 7-7 7"/>',
		'chevron-right' => '<path d="m9 6 6 6-6 6"/>',
		'alert-triangle' => '<path d="m12 3 10 17H2Z"/><path d="M12 9v4M12 17h.01"/>',
		'info' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
		'user' => '<circle cx="12" cy="8" r="4"/><path d="M5 21a7 7 0 0 1 14 0"/>',
		'user-plus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/>',
		'map-pin' => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
		'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/>',
		'shield-check' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/>',
		'key' => '<circle cx="7.5" cy="15.5" r="4.5"/><path d="m11 12 9-9"/><path d="M17 6l3 3"/><path d="M14 9l2 2"/>',
		'smartphone' => '<rect x="7" y="2" width="10" height="20" rx="2"/><path d="M12 18h.01"/>',
		'heart' => '<path d="M19.5 12.6 12 20l-7.5-7.4a5 5 0 1 1 7.5-6.6 5 5 0 1 1 7.5 6.6Z"/>',
		'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
		'external-link' => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
		'star' => '<path d="m12 3 2.9 5.9 6.5.9-4.7 4.6 1.1 6.5L12 17.8 6.2 20.9l1.1-6.5L2.6 9.8l6.5-.9L12 3Z"/>',
		'inbox' => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11Z"/>',
	];

	public static function render(string $name, ?string $extraClass = null): string
	{
		$inner = self::ICONS[$name] ?? null;
		if ($inner === null) {
			return '';
		}
		$class = 'iv-icon';
		if ($extraClass !== null && $extraClass !== '') {
			$class .= ' ' . htmlspecialchars($extraClass, ENT_QUOTES, 'UTF-8');
		}
		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="%s" aria-hidden="true" focusable="false">%s</svg>',
			$class,
			$inner,
		);
	}

	/** @return list<string> */
	public static function names(): array
	{
		return array_keys(self::ICONS);
	}
}
