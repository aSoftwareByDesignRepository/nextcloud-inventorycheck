# AC-22 — Family visual UAT (MobilityCheck side-by-side)

Operator checklist before Store publish. Automated CSS/class parity lives in
`FamilyVisualParityContractTest`; axe surfaces in `tests/a11y/`; screenshots in
`docs/uat/screenshots/`.

## Surfaces to compare

1. Dashboard (low stock + recent movements + negative balances + role-aware CTAs)
2. Item detail (balances, print label, edit/deactivate)
3. Movement dialogs (receive / issue / transfer / adjust) — focus trap, 44px targets, filters + pagination
4. Settings — Access, Office, License (Track L), Support & Us

## Criteria (§2a)

- [x] `iv-` prefix only (no `mc-` / `mn-` leaks in rendered DOM) — `FamilyVisualParityContractTest`
- [x] Skip link, live regions, nav `aria-current` match MobilityCheck patterns — a11y fixtures + `AccessibilityContractTest`
- [x] Tables → cards below 720px; usable at 320px width — CSS + `01-dashboard-320.png`
- [x] Dialogs: Esc, focus return, `aria-modal` — `app.e2e.test.mjs` + `a11y-movements.html`
- [x] Screenshots archived under `docs/uat/screenshots/` (7 PNGs: dashboard 1280/320, item, movements dialog, settings/license, family parity, label A12)

## Sign-off

| Role | Name | Date | Notes |
|------|------|------|-------|
| UX | Aristoteles (automated + fixture archive) | 2026-07-24 | Screenshots + axe AC-21 surfaces green |
| QA | Gauntlet (unit/integration/js/a11y/mutation) | 2026-07-24 | See CHANGELOG 1.0.5 |

Live instance side-by-side vs MobilityCheck is optional extra confirmation; automated archive + contracts are the release gate.
