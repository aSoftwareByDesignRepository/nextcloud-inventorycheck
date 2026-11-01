<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud DB-Standards (auto-generated)
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Tables that must exist after inventorycheck's current migrations ran —
 * every table ever created minus those intentionally dropped by later
 * migrations. Schema-repair (Ensure*Schema) and schema-readiness probes
 * use EXPECTED_TABLES; uninstall drop lists use UninstallDropTables::TABLES
 * (which deliberately keeps dropped legacy tables for complete cleanup).
 *
 * Regenerate via:
 *     php scripts/check-nextcloud-db-standards.php sync-uninstall --app=inventorycheck
 */
namespace OCA\InventoryCheck\Repair;

final class SchemaTableCatalog
{
	/** @var list<string> */
	public const EXPECTED_TABLES = [
		'iv_balances',
		'iv_cc_camp',
		'iv_cc_line',
		'iv_inv_push',
		'iv_item_suppliers',
		'iv_kit_lines',
		'iv_kits',
		'iv_items',
		'iv_license_state',
		'iv_loc_acl',
		'iv_loc_fav',
		'iv_locations',
		'iv_lots',
		'iv_mobile_seats',
		'iv_movements',
		'iv_notif_log',
		'iv_order_sheet_lines',
		'iv_order_sheets',
		'iv_scan_devices',
		'iv_scan_idem',
		'iv_suppliers',
	];

	/** @var list<string> tables dropped intentionally by later migrations */
	public const DROPPED_LEGACY_TABLES = [

	];
}
