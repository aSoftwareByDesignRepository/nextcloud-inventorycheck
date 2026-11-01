<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

/**
 * Restock security contract (SPEC §15 / C-1 / Q8): one place that decides
 * which item/movement keys may cross each serializer boundary.
 *
 * Channels:
 *   web-office   — everything
 *   web-field    — no money, no supplier fields, no on_order_by/at (neutral flag stays)
 *   mobile-seat  — never any money (Q8); supplier fields only for office seats
 *   mobile-device— never any money, never supplier fields (TH-08), never on_order_by/at
 */
final class MoneyVisibility
{
	/** Money-bearing item keys — never cross the mobile boundary, office-gated on web. */
	private const ITEM_MONEY_KEYS = [
		'lastPriceMinor',
		'orderPackPriceMinor',
	];
	/** Procurement-channel keys — office seats only, never devices. */
	private const ITEM_SUPPLIER_KEYS = [
		'supplierName',
		'supplierSku',
	];
	/** Internal PII — office web only, never mobile. */
	private const ITEM_ACTOR_KEYS = [
		'onOrderBy',
		'onOrderAt',
	];
	/** Money on movement payloads. */
	private const MOVEMENT_MONEY_KEYS = [
		'spendMinor',
	];

	/** @param array<string, mixed> $item */
	public static function itemForWeb(array $item, bool $isOffice): array
	{
		if ($isOffice) {
			return $item;
		}
		foreach (self::ITEM_MONEY_KEYS as $k) {
			unset($item[$k]);
		}
		foreach (self::ITEM_SUPPLIER_KEYS as $k) {
			unset($item[$k]);
		}
		unset($item['supplierNote']);
		foreach (self::ITEM_ACTOR_KEYS as $k) {
			unset($item[$k]);
		}
		return $item;
	}

	/**
	 * Mobile boundary: ALL callers get zero money fields (Q8). Devices
	 * additionally lose supplier fields; field seats lose them too.
	 *
	 * @param array<string, mixed> $item
	 */
	public static function itemForMobile(array $item, bool $isDevice, bool $isOffice): array
	{
		foreach (self::ITEM_MONEY_KEYS as $k) {
			unset($item[$k]);
		}
		foreach (self::ITEM_ACTOR_KEYS as $k) {
			unset($item[$k]);
		}
		if ($isDevice || !$isOffice) {
			foreach (self::ITEM_SUPPLIER_KEYS as $k) {
				unset($item[$k]);
			}
			unset($item['supplierNote']);
		}
		return $item;
	}

	/** @param array<string, mixed> $movement */
	public static function movementForWeb(array $movement, bool $isOffice): array
	{
		if ($isOffice) {
			return $movement;
		}
		foreach (self::MOVEMENT_MONEY_KEYS as $k) {
			unset($movement[$k]);
		}
		return $movement;
	}

	/** @param array<string, mixed> $movement */
	public static function movementForMobile(array $movement): array
	{
		foreach (self::MOVEMENT_MONEY_KEYS as $k) {
			unset($movement[$k]);
		}
		return $movement;
	}
}
