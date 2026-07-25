# InventoryCheck

Stock levels, locations, and append-only movements on Nextcloud — ready for QR scan (Track L).

**Standalone repository:** [github.com/aSoftwareByDesignRepository/nextcloud-inventorycheck](https://github.com/aSoftwareByDesignRepository/nextcloud-inventorycheck)  
App ID: **`inventorycheck`**. Clone path:

```bash
git clone https://github.com/aSoftwareByDesignRepository/nextcloud-inventorycheck.git /path/to/nextcloud/apps/inventorycheck
```

## Features

- Items (SKU / scan code), locations (warehouse / van / site), per-location balances
- Movements: receive, issue, transfer, adjust; reverse with a compensating booking
- Low-stock signals; printable item labels (SVG + browser print)
- Access control: allowed groups, office groups, app admins
- Optional mobile license seats and scanner device pairing (Track L)
- Support & Us in Settings

## Requirements

- Nextcloud 32–34
- PHP 8.2–8.5
- MySQL/MariaDB or PostgreSQL

## Install

Enable the app in Apps, or:

```bash
cd nextcloud
docker compose exec nextcloud php occ app:enable inventorycheck
```

First install seeds a small demo warehouse + three SKUs once (`demo_seeded`). Deleting them will not resurrect them on repair.

## Development

```bash
cd nextcloud
docker compose exec nextcloud bash -lc 'cd /var/www/html/custom_apps/inventorycheck && composer install && composer test:gauntlet'
```

Host-side JS contracts:

```bash
cd nextcloud/apps/inventorycheck && npm test 2>/dev/null || node --test --test-concurrency=1 tests/js/*.test.mjs tests/js/*.e2e.test.mjs
```

## License

AGPL-3.0-or-later
