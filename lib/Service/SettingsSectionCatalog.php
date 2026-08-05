<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCP\IL10N;

/**
 * Single source of truth for InventoryCheck settings sub-pages.
 *
 * Artifacts that must stay in sync (pinned by contract tests):
 *  - appinfo/routes.php `{section}` requirement
 *  - PageController validation / titles / URL map
 *  - templates/common/navigation.php + parts/settings-nav.php
 *  - js/settings-legacy-redirect.js LEGACY_ANCHORS mirror
 *  - js/app.js section switch in renderSettings
 */
final class SettingsSectionCatalog
{
	public const DEFAULT_SECTION = 'access';

	/**
	 * Ordered section slugs — order drives sidebar + chip bar.
	 *
	 * @var list<string>
	 */
	public const SECTIONS = [
		'access',
		'office',
		'notifications',
		'quantities',
		'location-access',
		'connections',
		'policies',
		'license',
		'support',
	];

	/**
	 * Legacy mega-page anchors → owning section slug.
	 *
	 * @var array<string, string>
	 */
	public const LEGACY_ANCHORS = [
		'iv-access-title' => 'access',
		'iv-access-restriction' => 'access',
		'iv-app-admins' => 'access',
		'iv-app-admins-hint' => 'access',
		'iv-office-title' => 'office',
		'iv-allow-negative' => 'office',
		'iv-notify-title' => 'notifications',
		'iv-reorder-hint' => 'notifications',
		'iv-frac-title' => 'quantities',
		'iv-loc-acl' => 'location-access',
		'iv-acl-list' => 'location-access',
		'iv-flange-maint' => 'connections',
		'iv-flange-project' => 'connections',
		'iv-flange-default-loc' => 'connections',
		'iv-require-adjust-reason' => 'policies',
		'iv-require-location-scan' => 'policies',
		'iv-license' => 'license',
		'iv-license-title' => 'license',
		'iv-license-key' => 'license',
		'iv-support-us' => 'support',
		'iv-support-us-title' => 'support',
	];

	public function isSection(string $section): bool
	{
		return in_array($section, self::SECTIONS, true);
	}

	public static function routeRequirement(): string
	{
		return implode('|', self::SECTIONS);
	}

	public function label(IL10N $l, string $section): string
	{
		return match ($section) {
			'access' => $l->t('Access control'),
			'office' => $l->t('Office / storekeeper'),
			'notifications' => $l->t('Low stock notifications'),
			'quantities' => $l->t('Fractional quantities'),
			'location-access' => $l->t('Location access'),
			'connections' => $l->t('Connections to other Check apps'),
			'policies' => $l->t('Scan & adjust policies'),
			'license' => $l->t('Official mobile & scanner licenses'),
			'support' => $l->t('Support & us'),
			default => $l->t('Settings'),
		};
	}

	public function navLabel(IL10N $l, string $section): string
	{
		return match ($section) {
			'access' => $l->t('Access'),
			'office' => $l->t('Office'),
			'notifications' => $l->t('Notifications'),
			'quantities' => $l->t('Quantities'),
			'location-access' => $l->t('Locations'),
			'connections' => $l->t('Connections'),
			'policies' => $l->t('Policies'),
			'license' => $l->t('License'),
			'support' => $l->t('Support us'),
			default => $l->t('Settings'),
		};
	}

	public function help(IL10N $l, string $section): string
	{
		return match ($section) {
			'access' => $l->t('Decide who may open InventoryCheck. Restriction takes effect immediately for non-administrators.'),
			'office' => $l->t('Who may receive, adjust, and run stocktakes — and whether negative stock is allowed.'),
			'notifications' => $l->t('Who gets a notice the first time an item drops below its reorder level.'),
			'quantities' => $l->t('Optional milli-unit storage for cable metres and similar. Enabling cannot be undone.'),
			'location-access' => $l->t('Optional. Limit field users to granted locations. Office and admins still see everything.'),
			'connections' => $l->t('Let MaintenanceCheck or ProjectCheck issue stock automatically when you turn this on.'),
			'policies' => $l->t('Warehouse hygiene for phone scan and inventur — reason codes and location scan confirm.'),
			'license' => $l->t('Paste an official IV2 key, assign mobile seats, and create scanner device slots.'),
			'support' => $l->t('How to get help, report bugs, and support InventoryCheck development.'),
			default => '',
		};
	}
}
