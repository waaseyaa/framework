# FW-2833 — package-owned auth UI scaffold sources

- Issue mirror: `waaseyaa/framework#2833`
- Consumer blocker: `jonesrussell/content-pipeline#36`
- Related pattern: #2832 (`sync-rules` owns Foundation resources)
- Contract: `docs/specs/auth-consumer-extensions.md`, `docs/specs/cli-kernel.md`

## Problem

`AuthUiScaffoldManager::sourceContext()` only looked at the application root
and an installed `waaseyaa/framework` metapackage. ADR-004 direct-package
consumers omit that aggregate, so `scaffold:auth --check` exited 1 with
"Framework auth UI sources were not found … waaseyaa/framework package" even
though the loaded `waaseyaa/cli` path-install still sits beside
`packages/admin/app` in the monorepo.

## Decision

Keep project-root and metapackage lookups. Add owning-package candidates that
follow the loaded `AuthUiScaffoldManager` / `waaseyaa/cli` install path to the
sibling `packages/admin/app`, using `realpath` so Composer path symlinks resolve.
Version identity prefers the monorepo `VERSION` beside that tree, then
InstalledVersions for `waaseyaa/framework` or `waaseyaa/cli`.

No application-side fallback, vendor-path workaround, metapackage
reintroduction, or relocation of admin SPA sources into the CLI package.

## Proof

- Unit: sibling-only candidate resolves and `scaffold:auth --check` / publish
  succeed without in-tree `packages/` or `vendor/waaseyaa/framework`.
- Existing metapackage and in-tree fixtures remain green.
- Content Pipeline direct profile requalifies `scaffold:auth --check` against
  this candidate's CLI package.
