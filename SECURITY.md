# Security Policy

## Supported versions

Security fixes are applied to the latest published InventoryCheck release on the Nextcloud App Store and to the `master` / default branch of [nextcloud-inventorycheck](https://github.com/aSoftwareByDesignRepository/nextcloud-inventorycheck).

## Reporting a vulnerability

Do **not** open a public GitHub issue for security problems that could expose data, credentials, or a practical exploit path.

Email the maintainer listed in `appinfo/info.xml` (`info@software-by-design.de`) with:

- App version (`occ app:list` / `appinfo/info.xml`)
- Nextcloud and PHP versions
- Steps to reproduce
- Impact assessment (who can trigger it, what is exposed)

We aim to acknowledge reports within a few business days.

## Please do not include

Production passwords, session cookies, customer dumps, or live hostnames in public trackers or screenshots attached to public issues.
