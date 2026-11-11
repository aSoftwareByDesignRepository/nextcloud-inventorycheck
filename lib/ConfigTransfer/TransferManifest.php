<?php

declare(strict_types=1);

/**
 * InventoryCheck transfer manifest — every appconfig key and every
 * inventorycheck iv_* table classified exactly once
 * (gate: scripts/check-config-transfer-manifest.py).
 */

namespace OCA\InventoryCheck\ConfigTransfer;

final class TransferManifest extends AbstractTransferManifest
{
	private const SCHEMA_VERSION = 1;

	public function schemaVersion(): int
	{
		return self::SCHEMA_VERSION;
	}

	/** @return array<string,array> */
	public function keys(): array
	{
		return self::normaliseKeys([
			// ── access door / admin policy — app-local appconfig, written
			// atomically through AccessControlService::saveAppPolicy (never
			// raw setAppValue; restricted-door-requires-allowlist is a
			// cross-key invariant → mirrored class) ──
			'access_restriction_enabled' => ['class' => 'mirrored', 'security' => true, 'section' => 'access'],
			'access_allowed_user_ids' => ['class' => 'mirrored', 'security' => true, 'refs' => ['kind' => 'uid_list_json'], 'section' => 'access'],
			'access_allowed_group_ids' => ['class' => 'mirrored', 'security' => true, 'refs' => ['kind' => 'gid_list_json'], 'section' => 'access'],
			'app_admin_user_ids' => ['class' => 'mirrored', 'security' => true, 'refs' => ['kind' => 'uid_list_json'], 'section' => 'access'],

			// ── office (L3) role + stock-integrity policy ──
			'office_user_ids' => ['class' => 'plain', 'security' => true, 'refs' => ['kind' => 'uid_list_json'], 'section' => 'office'],
			'office_group_ids' => ['class' => 'plain', 'security' => true, 'refs' => ['kind' => 'gid_list_json'], 'section' => 'office'],
			'allow_negative_stock' => ['class' => 'plain', 'security' => true, 'section' => 'office'],

			// ── notifications ──
			'low_stock_notify_user_ids' => ['class' => 'plain', 'refs' => ['kind' => 'uid_list_json'], 'section' => 'notifications'],
			'low_stock_notify_group_ids' => ['class' => 'plain', 'refs' => ['kind' => 'gid_list_json'], 'section' => 'notifications'],
			'location_reorder_hint_enabled' => ['class' => 'plain', 'section' => 'notifications'],

			// ── quantities ──
			// qty_scale is enable-only in the app (0 → 3 milli-units). The
			// hooks validator refuses a downgrade and any cross-scale write
			// onto a populated target — only equal values or 0→3 on an
			// empty ledger may land, so transferred milli-scaled rows can
			// never be silently misread as whole units.
			'qty_scale' => ['class' => 'plain', 'section' => 'quantities'],

			// ── connections to other Check apps — flange flags open a
			// server-side stock-issue write path for foreign apps →
			// security-relevant grants ──
			'maint_flange_enabled' => ['class' => 'plain', 'security' => true, 'section' => 'connections'],
			'project_flange_enabled' => ['class' => 'plain', 'security' => true, 'section' => 'connections'],
			'invoice_flange_enabled' => ['class' => 'plain', 'security' => true, 'section' => 'connections'],
			// Instance-bound: references an iv_locations row id — row ids
			// never remap through config keys (NN-10).
			'flange_default_location_id' => ['class' => 'env', 'section' => 'connections'],

			// ── product lookup — outbound barcode calls to third-party
			// databases are a privacy-relevant opt-in → security group ──
			'product_lookup_enabled' => ['class' => 'plain', 'security' => true, 'section' => 'product-lookup'],
			'product_lookup_autofire' => ['class' => 'plain', 'section' => 'product-lookup'],
			'product_lookup_providers' => ['class' => 'plain', 'section' => 'product-lookup'],
			'product_lookup_stats' => 'internal', // runtime counters — per-instance

			// ── scan & adjust policies ──
			'require_adjust_reason' => ['class' => 'plain', 'section' => 'policies'],
			'require_location_scan' => ['class' => 'plain', 'section' => 'policies'],

			// ── location ACL — instance-bound: iv_loc_acl subjects mix
			// user/group/device ids (device rows can never remap) so the
			// table stays internal and the toggles stay local too — the
			// target keeps its own (fail-closed) state instead of carrying
			// an enabled flag whose rows vanished.
			'location_acl_enabled' => ['class' => 'env', 'section' => 'location-access'],
			'location_acl_devices_strict' => ['class' => 'env', 'section' => 'location-access'],

			// ── restock & procurement ──
			'restock_budgets' => ['class' => 'plain', 'section' => 'restock'],
			'category_list' => ['class' => 'plain', 'section' => 'restock'],
			'on_order_stale_days' => ['class' => 'plain', 'section' => 'restock'],
			'expiry_warn_days' => ['class' => 'plain', 'section' => 'restock'],
			'velocity_window_days' => ['class' => 'plain', 'section' => 'restock'],
			'restock_suggestion_mode' => ['class' => 'plain', 'section' => 'restock'],
			// Spend controls — money policy → security group.
			'order_approval_threshold_minor' => ['class' => 'plain', 'security' => true, 'section' => 'restock'],
			'restock_emergency_budget_minor' => ['class' => 'plain', 'security' => true, 'section' => 'restock'],
			'restock_digest_enabled' => ['class' => 'plain', 'section' => 'restock'],
			// Webhook endpoint is instance/integration-bound (NN-10); the
			// HMAC secret is never exported; the kill-switch travels with
			// the endpoint as one env unit.
			'restock_webhook_url' => ['class' => 'env', 'section' => 'restock'],
			'restock_webhook_disabled' => ['class' => 'env', 'section' => 'restock'],
			'restock_webhook_secret' => ['class' => 'secret', 'section' => 'restock'],
			'restock_digest_last_run' => 'internal', // runtime state

			// ── internal bookkeeping — never transferred ──
			'demo_seeded' => 'internal',
			'uninstall_repair_pass' => 'internal',
			'upgrade_backup_last_snapshot_id' => 'internal',
			'upgrade_backup_max_snapshots' => 'internal',
			'transfer_receipts' => 'internal',
			'transfer_job_state' => 'internal',
			'transfer_last_import_at' => 'internal',
			// Dynamic/prefix key families — prefixes, not keys; members are
			// dynamic and never transferred by definition:
			//   'pair_fail:*'          pairing rate-limit buckets
			//   'upgrade_backup_*'     snapshot-restore rewrites restored keys
		]);
	}

	/** @return array<string,array> */
	public function tables(): array
	{
		return self::normaliseTables([
			// ══ master data ══
			'iv_locations' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['code']],
			'iv_suppliers' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['name']],
			// (sku, scan_code) is the CSV import's upsert identity; each
			// component is individually unique, so the pair is too.
			// photo/delivery-note columns point at appdata blobs — the pack
			// exports rows only, so they are env-dropped (bc_tx_attachments
			// class).
			'iv_items' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['sku', 'scan_code'],
				'fk' => ['default_location_id' => 'iv_locations'],
				'depends_on' => ['iv_locations'],
				'env_columns' => ['photo_name', 'photo_mime', 'delivery_note_path']],
			'iv_item_suppliers' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['item_id', 'supplier_id'],
				'fk' => ['item_id' => 'iv_items', 'supplier_id' => 'iv_suppliers'],
				'depends_on' => ['iv_items', 'iv_suppliers']],
			'iv_kits' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['name']],
			'iv_kit_lines' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['kit_id', 'item_id'],
				'fk' => ['kit_id' => 'iv_kits', 'item_id' => 'iv_items'],
				'depends_on' => ['iv_kits', 'iv_items']],
			'iv_lots' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['item_id', 'lot_code'],
				'fk' => ['item_id' => 'iv_items'], 'depends_on' => ['iv_items']],

			// ══ stock ledger ══
			// Movements have no honest natural key (legitimate duplicate rows
			// exist) — spec default: seed-if-empty on merge, wipe+load on
			// replace. created_by carries verbatim: it may hold 'device:N'
			// scanner actors a uid check would skip, and a ghost uid only
			// degrades a label. ref_id references foreign Check-app rows —
			// env-dropped (dutycheck tck_round_id class); ref_type stays for
			// audit context.
			'iv_movements' => ['class' => 'business', 'strategy' => 'seed-if-empty',
				'fk' => [
					'item_id' => 'iv_items',
					'location_id' => 'iv_locations',
					'counterparty_loc_id' => 'iv_locations',
				],
				'depends_on' => ['iv_items', 'iv_locations'],
				'env_columns' => ['ref_id']],
			// Balances are the materialised projection of the same ledger —
			// same seed-if-empty gate keeps both sides consistent (a balance
			// row only exists after a movement, so the two tables can never
			// gate differently on a sane target). RebuildBalancesCommand is
			// the manual heal if an operator ever drifts them apart.
			'iv_balances' => ['class' => 'business', 'strategy' => 'seed-if-empty',
				'fk' => ['item_id' => 'iv_items', 'location_id' => 'iv_locations'],
				'depends_on' => ['iv_items', 'iv_locations']],

			// ══ cycle counts + order sheets — transactional, no honest
			// natural key → seed-if-empty ══
			'iv_cc_camp' => ['class' => 'business', 'strategy' => 'seed-if-empty',
				'fk' => ['location_id' => 'iv_locations'],
				'depends_on' => ['iv_locations']],
			'iv_cc_line' => ['class' => 'business', 'strategy' => 'seed-if-empty',
				'fk' => [
					'campaign_id' => 'iv_cc_camp',
					'item_id' => 'iv_items',
					'posted_mov_id' => 'iv_movements',
				],
				'depends_on' => ['iv_cc_camp', 'iv_items', 'iv_movements']],
			'iv_order_sheets' => ['class' => 'business', 'strategy' => 'seed-if-empty',
				'fk' => ['supplier_id' => 'iv_suppliers'],
				'depends_on' => ['iv_suppliers']],
			'iv_order_sheet_lines' => ['class' => 'business', 'strategy' => 'seed-if-empty',
				'fk' => ['sheet_id' => 'iv_order_sheets', 'item_id' => 'iv_items'],
				'depends_on' => ['iv_order_sheets', 'iv_items']],

			// ══ per-user state ══
			'iv_loc_fav' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['user_id', 'location_id'],
				'fk' => ['location_id' => 'iv_locations'], 'depends_on' => ['iv_locations'],
				'uid_ref' => ['user_id']],

			// ══ internal / env / log — NEVER transferred ══
			// Location ACL subjects mix user|group|device ids in one column —
			// device subjects reference iv_scan_devices (internal, pairing
			// secrets) and the engine has no per-row-type ref check; the
			// honest class is internal, and the ACL config toggles above are
			// env so the target keeps its own fail-closed state.
			'iv_loc_acl' => ['class' => 'internal'],
			// License + seat + scanner state is bound to this instance's id,
			// the vendor signature, and per-device bearer secrets — never
			// transferable.
			'iv_license_state' => ['class' => 'internal'],
			'iv_mobile_seats' => ['class' => 'internal'],
			'iv_scan_devices' => ['class' => 'internal'],
			'iv_scan_idem' => ['class' => 'internal'],
			// Outbound push ledger to InvoiceCheck — issue_ref + payload are
			// bound to the source instance's integration state.
			'iv_inv_push' => ['class' => 'internal'],
			'iv_notif_log' => ['class' => 'log'],
		]);
	}
}
