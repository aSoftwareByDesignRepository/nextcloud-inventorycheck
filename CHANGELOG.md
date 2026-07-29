# Changelog

## 1.2.12 — 2026-07-26

- **Labels:** every printable label now includes **QR + Code 128** of the same `scan_code` (phone cameras and wedge/laser scanners).
- **UX:** item detail shows an inline label preview with print + SVG download; selectable scan-code text kept for A12.
- **Tests:** Code128 unit suite + label MSI harness; a11y/e2e contracts for barcode preview.
- **Tooling:** `composer test:mutation` aggregate now includes the label / qty-scale / stock-issue-facade harnesses (parity with the `test:msi` gate, which already ran them).

## 1.2.11 — 2026-07-26

- **B1:** block item/location delete and deactivate while referenced by an open/counting stocktake (`item_in_open_stocktake` / `location_in_open_stocktake`) — prevents permanently bricked inventur close.
- **A2∩A3:** CSV opening receives defer low-stock notify until after the import TX commits (parity with inventur/flange).

## 1.2.10 — 2026-07-26

- **C1:** item create/update reorder ceiling uses `QtyScale::maxStorage` (display >1000 works under fractional; matches CSV).
- **B1∩C2:** inventur close fails closed with `track_mode_changed` if a line item flips to lot/serial mid-campaign (no bricked `invalid_lot_code` close).

## 1.2.9 — 2026-07-26

- **B2 race:** unique index `iv_mov_ref_uq` on `(ref_type, ref_id, item_id)` so concurrent flange posts cannot double-issue the same SKU for one peer ref (NULLs for manual UI movements stay multi-row safe).
- **C2/S5:** restore item-row lock in `lockActiveItemForMovement` (serial exclusive / shared otherwise) — regression had used unlocked `findById`.
- **Tests:** Wave A/B suite resets `qty_scale` so CSV opening balances are not polluted by fractional Wave C runs.
- **Docs:** planning baseline + CHANGELOG aligned with live `info.xml`.

## 1.2.8 — 2026-07-26

- **B2∩A3:** flange bundle defers low-stock notify until after the outer TX commits (no phantom Notifications/Activity on rolled-back kits).
- **B2:** multi-line `insufficient_stock` reports the failing SKU, not the first line.

## 1.2.7 — 2026-07-26

- **B1 deadlock:** inventur `close()` locks location → items (asc) → balances (matches MovementService); `setCount` locks campaign before line (no ABBA vs close); low-stock notify deferred until after inventur TX commits.
- **i18n:** seven interpolating CSV/stocktake/label strings added EN+DE; l10n parity regex now catches `tr('…', {…})`.

## 1.2.6 — 2026-07-26

- **B1 race:** inventur `close()` locks all campaign balances `FOR UPDATE` before the conflict decision (kills TOCTOU wipe vs concurrent receive/issue).
- **C3:** cycle-count list filters location ACL in SQL (correct totals/pages; no 200-row post-filter window).
- **C1∩B2:** flange/facade success payloads return display qty (not storage milli-units).
- **Tests:** dual-process close×receive race; lockPairs-before-conflict contract + mutation needle.

## 1.2.5 — 2026-07-26

- **B1 / UC-C2:** cycle-count close fails closed on mid-count stock drift (`count_conflict`) unless office acknowledges; lines expose `currentQty` + `conflict`; stocktake UI shows live qty and text status (not color alone).
- **C1 ∩ B2/C5:** `StockIssueFacade` converts peer display qty → storage (fixes under-issue after fractional enable); HTTP flange no longer double-converts.
- **a11y:** stocktake axe fixture + live route; conflict close confirmations labelled.
- **Tests:** inventur conflict integration, facade scale unit + mutation needles.

## 1.2.4 — 2026-07-26

- **B1:** cycle-count close skips matching counts (no adjust noop abort) so inventur closes when shelf matches system.
- **C3:** cycle-count list/get honour location ACL (IDOR-safe; inaccessible campaigns 404 as `unknown_location`).
- **C1/A3 honesty:** insufficient-stock toasts and low-stock notify/activity params use display qty (not milli storage).
- **A3:** Activity provider registered so low-stock events render in the stream.
- **UX:** pagination omits dead Previous/Next (prefer omit over disable).

## 1.2.3 — 2026-07-26

- **A3:** low-stock notifications fire only on *newly enters* (open-episode key); recovery clears the episode; ≤1 fire / item / 24h; activity published to every recipient.
- **B2/C5:** `StockIssueFacade` honour IV flange toggles (no split-brain vs Settings).
- **A2:** CSV import accepts description / active / supplier_note / last_price_minor / opening_* (UI hint matches).
- **A5:** bulk “Print labels” omitted until selection; label sheets chunk into A4 pages of 12.
- **A1:** exports over 50k rows fail with `export_too_large` (no silent truncate).
- **A6:** CORE-APP-PLAN §4 baseline updated to shipped Waves A–C.

## 1.2.2 — 2026-07-26

- **Security (C3):** `byCode` balances, global low-stock sums, and location favourites honour location ACL (no hidden-van IDOR).
- **Devices:** `device:…` actors stay unrestricted under location ACL so scanners are not fail-closed when web field ACL is on.
- **A1:** CSV import accepts German headers (`Artikelnummer`, `Bezeichnung`, …); export supports `?lang=de`.
- **UX:** fractional settings omit the dead “already on” button; location ACL uses a multi-select of locations instead of raw ids.
- **Tests:** lot-mode movements, byCode/favourites/device ACL, DE CSV round-trip keys, QtyScale enable migration unit proof, a11y settings Wave C sections.

## 1.2.1 — 2026-07-26

- **Security (C3):** movement history and per-location low-stock respect location ACL (IDOR-safe empty results for hidden locations).
- **C1:** movement qty ceiling uses `QtyScale::maxStorage` so fractional bookings above 1000 display units work; CSV export writes display quantities for import round-trips.
- **C2∩B1:** cycle-count campaigns skip lot/serial SKUs (inventur without lot would break ledger invariants).
- **Mobile API:** scan accepts `lotCode` and display qty via `QtyScale`; list/bootstrap paths format quantities.
- **ACL writes:** `setForSubject` validates user/group/location existence (parity with access lists).
- **Flange HTTP:** line qty converted display→storage before issue.

## 1.2.0 — 2026-07-26

- **Fractional quantities (C1):** optional one-way enable (`POST /api/config/fractional`) rescales ledger ints ×1000; API returns display decimals; UI uses 0.001 steps; companion `companionApi` bumps to 2 when enabled.
- **Lot / serial tracking (C2):** items have `trackMode` (`none`/`lot`/`serial`); movements accept `lotCode`; serial capacity is one display unit (scale-aware); exclusive item lock prevents races.
- **Location ACL (C3):** optional per-location grants for field users (`GET`/`PUT /api/config/location-acl`); inaccessible locations 404 like unknown (IDOR-safe); office/admins unrestricted.
- **ProjectCheck flange (C5):** `issueForProject` + settings toggle; soft no-op when peer absent or disabled.
- **UX:** settings for fractional + location ACL; item tracking mode; lot/serial on movement dialogs; favourites default in movements (B4); per-location low-stock on dashboard (B3).
- **Lifecycle:** `iv_loc_acl` in uninstall/backup; migration `Version1003`.

## 1.1.0 — 2026-07-26

- **CSV import/export:** `/api/export` streams items, locations, balances, movements, and a DATEV-style movements export as a download; `/api/import/dry-run` and `/api/import/commit` validate and post item rows from pasted text or an uploaded file (office-only, dry-run required before commit).
- **Item photos:** office users can upload, replace, and remove one photo per item (`ItemPhotoController`, stored under app data via `IAppData`); item detail shows the photo with `alt` text set to the item name.
- **Cycle counts (stocktake):** new `/stocktake` area lets office users snapshot system quantities per location, enter counted quantities, and close the count — posting one stock adjustment per counted line. Uncounted items can be left unchanged on close.
- **Location favourites:** any user can star/unstar locations for quick access from the location list.
- **Low-stock notifications:** app admins can configure user/group lists that get a notification (Nextcloud notifications + activity) the first time an item drops below its reorder level, at most once per item per day. Optional per-location reorder hints via `LowStockService::listPerLocation()`.
- **Flange integration groundwork:** `FlangeService`/`FlangeController` expose status and settings so sibling apps (MaintenanceCheck, ProjectCheck) can be allowed to issue stock automatically via `MovementService::issueWithRef()` (server-only path — never reachable through `MovementController`, immutable `ref_type`/`ref_id`, S16).
- **Bulk labels:** items list supports selecting multiple items and printing an A4 label sheet (`/api/items/labels`).
- **Items:** `supplierNote` and `lastPriceMinor` are now editable on create/update and shown on item detail.
- **Mobile gate:** `bootstrap` now advertises `companionApi` and per-feature `capabilities` (`csv`, `photos`, `cycleCount`, `bulkLabels`) for companion clients; mobile app status stays "coming soon".
- **Lifecycle:** uninstall and upgrade-backup now cover the new tables (`iv_notif_log`, `iv_cc_camp`, `iv_cc_line`, `iv_loc_fav`) and the `item_photos` app-data folder.

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
