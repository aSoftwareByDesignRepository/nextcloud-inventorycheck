# Changelog

## 1.0.9 — 2026-07-25

- **Security (access lists):** Config saves validate every user/group id against the Nextcloud directory — unknown uids → 422 `unknown_user`, unknown gids → 422 `unknown_group`, lists unchanged. Stops silent allow-list typos from locking people out when restriction is on (MaintenanceCheck pattern).
- **Atomic writes:** All present list fields are validated before any appconfig key is mutated — a bad group cannot leave a half-applied user list.
- **Bool wire:** `accessRestrictionEnabled` / `allowNegativeStock` accept JSON booleans and `'0'`/`'1'` correctly (`(bool)'0'` is never used — that PHP footgun would force-enable).
- **UX:** Settings maps those 422 details to inline field errors (`aria-invalid`) plus toast.
- **Tests:** unit + integration + mutation harness for ConfigController directory checks (incl. partial-write and `'0'` bool); E2E asserts unknown allow-list user → 422.

## 1.0.8 — 2026-07-25

- **Settings (IMPLEMENTATION §2.1):** Delegated app-administrator list is visible and editable by Nextcloud system admins (L0); L1 sees it read-only. Config API exposes `isSystemAdmin`.
- **E2E (SPEC §14.3):** UJ-1…UJ-7 journeys cover receive/transfer/same-location, insufficient stock, adjust no-op, low-stock boundary, reverse, and settings/app-admins — with axe on key surfaces.
- **Design system:** Section chrome and skip-link spacing use `--iv-space-*` / `--iv-fs-*` tokens (65 ch reading measure on section hints).

## 1.0.7 — 2026-07-25

- **AC-20:** live uninstall drop integration — all 7 `iv_*` tables + appconfig cleared, schema ensurer restores, appconfig snapshot restored for gauntlet continuity
- **N5:** `hashSecret` fails closed when instance `secret` is empty (no static APP_ID pepper); HMAC pepper unit + mutation coverage
- **AC-18:** pairing failure accounting re-checks rate limit under the same exclusive lock (TOCTOU cannot overshoot RATE_MAX)
- **Mobile:** `pairDevice` is `#[NoAdminRequired]` like every other mobile route
- **UX:** movement dialogs disable Confirm/Cancel while pending (no double-submit)
- **Contracts:** mutating API methods must not be `NoCSRFRequired`; N6 provider portability source contracts; N1/N2 latency smoke on warm Docker

## 1.0.6 — 2026-07-24

- **Critical (pages):** `#[NoCSRFRequired]` on all browser page GETs and label print/SVG routes — Nextcloud 34 CSRF middleware was returning 412 on every shell navigation (sibling family apps already had this)
- **Critical (API):** `requestToken()` now reads `OC.requestToken` / `<head data-requesttoken>` — NC34 dropped `<meta name="requesttoken">`, so every SPA fetch was 412 and pages showed “Could not load …”
- **Races:** ConcurrentIssueLock pins `allow_negative_stock=false` in parent + workers (sibling tests flipping the flag no longer let both issues “win”)
- **A11y:** toast region uses `role="region"` with `aria-label` (WCAG 4.1.2)
- **E2E:** Playwright API login + shared storageState; live axe + UJ + UAT screenshots; MSI gate green
- **Security:** pair/device secrets remain HMAC-SHA256 with instance pepper

## 1.0.5 — 2026-07-24

- **AC-21:** axe WCAG 2.1 AA on dashboard, item detail, movements (filters + receive dialog), settings/license fixtures — zero serious/critical
- **AC-22:** screenshot archive under `docs/uat/screenshots/` (7 PNGs incl. 320px + label A12 + family parity); checklist signed; contract requires PNGs + checked criteria
- **API tests:** miss item/location → `unknown_item`/`unknown_location`; `balances?negative=1`; movement kind/item filters
- **JS:** pure helpers exported + unit-tested (`canReverseMovement`, `movementListQuery`, `dateInputToUnix`)
- **Mutation:** BalanceMapper negative filter (3), expanded Iv2Codec (7), Seat/DeviceRank (7); survivors none — MSI-equivalent custom harness (Infection not used; same kill proof)

## 1.0.4 — 2026-07-24

- **UX (UJ-3):** insufficient-stock toasts now name available qty and location (`Only %s left in %s.`) — fixed broken `%n` misuse of `IL10N::t()`
- **API contract:** missing masters return 404 `unknown_item` / `unknown_location` (§4.1)
- **Mobile gate (AC-17/18):** garbage `X-IV-Device-Token` → 401 `auth_required`; deactivated slot keeps token hash → 402 `device_required` (§9.3.4)
- **UX:** movements page filters (kind, item, location, from/to) + pagination; dashboard lists negative balances when negatives are off (S4); transfer Reverse only on the out leg
- **Balances API:** `?negative=1` lists qty &lt; 0
- Tests: envelope message assertions, device gate ladder cases, JS filter/pagination contracts

## 1.0.3 — 2026-07-24

- **Security (AC-2/P1):** `isAccessRestrictionEnabled()` compared with `=== '1'` again — a `!==` flip made default-open behave as default-locked (and the reverse when admins enabled restriction). Regression unit test pins the raw `'0'`/`'1'` byte semantics; mutation harness now refuses to run if the ACL baseline is already red.
- **API:** scan `transfer` without `toLocationId` returns 422 `validation_failed` on that field instead of a opaque 404 on location `0`.
- **Ops (S13):** `occ inventorycheck:rebuild-balances` opens one transaction per item (not one global TX) so repair stays bounded under live traffic.

## 1.0.2 — 2026-07-24

- **PostgreSQL (N6):** `ensureZeroRow` now uses `insertIgnoreConflict` — a raced duplicate insert no longer aborts the movement transaction on PostgreSQL
- **Races (S5/S6):** movements take shared row locks on item/location inside the transaction; deactivate/delete take exclusive locks before the zero-balance / movement-reference checks — stock can no longer be stranded on an entity deactivated mid-movement
- **Races (S7/S8):** item code changes serialise under an app lock; DB unique-violation races surface as 409 `code_exists` instead of 500
- **UX:** transfer insufficient-stock errors now name the source location like issues do
- **API contract:** `GET /api/items?lowStock=` filter implemented (S10 over the item list); canonical `GET /api/items/{id}/label` route added alongside `label.svg`; error envelope always carries `details` (§7.1)
- Tests: dual-process deactivate-vs-movement races, provider-aware lock-suffix unit tests, 4 new movement-engine mutants (7/7 killed)

- **Security (AC-17):** mobile bootstrap requires auth (rungs 1–2); anonymous callers get **401** `auth_required` (not 402 `seat_required`); licensing envelope no longer public
- **Races (AC-16/18):** exclusive locks around seat assign / device create; atomic `rotatePairCode`; pair claim requires `token_hash IS NULL`
- **Schema:** unique indexes on `iv_scan_devices.token_hash` / `pair_code_hash` (NULLs still allowed)
- **Pairing:** contended rate-limit lock retries instead of immediate false 429
- Tests: mobile gate HTTP ladder, ref_type DB round-trip, unique-index contracts

## 1.0.0 — 2026-07-24

First production release of InventoryCheck: stock ledger on Nextcloud with items, locations, balances, and an append-only movement history.

- **Ledger:** receive / issue / transfer / adjust with `FOR UPDATE` lock order; immutable rows; reverse via compensating movements
- **ACL:** L0–L3 (allow groups, office groups, app admin); field vs office movement matrix; mobile API gated separately
- **Track L:** seats, device slots, one-time pair codes, atomic claim, rate-limited pairing under exclusive lock
- **Labels:** SVG download + printable blank view (`/items/{id}/label`) with print CSS (A12)
- **UX:** Check-family `iv-` shell, empty states, inline 422 field errors, balance flash, EN/DE l10n, Support & Us
- **Ops:** schema ensure + one-shot demo seed (`demo_seeded`), uninstall drop, upgrade backups, rebuild-balances
- **Gauntlet:** unit, integration, JS contracts, custom mutation killers
