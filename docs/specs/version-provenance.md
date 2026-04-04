# Waaseyaa version and provenance

## Authoritative revision

- The **Waaseyaa monorepo** does not publish a meaningful semantic version in root `composer.json`. The `"version"` field there is a **Composer internal artifact** and must not be used for compatibility or release decisions.
- The **only authoritative revision** of the framework is the **Git commit SHA** of `waaseyaa/framework` on the branch you deploy (typically `main`).

## Split packages (`waaseyaa/*`)

Published consumers resolve packages as:

- `0.1.0-alpha.N` (Packagist)
- `0.1.x-dev` / `dev-main` (VCS)
- `path` repositories pointing into a checkout of the monorepo

Split tags are produced from the monorepo; **all `waaseyaa/*` packages installed for one app should correspond to a single monorepo SHA** (either via path checkout or via a coherent lockfile from one split publish).

## Golden SHA (apps and CI)

Apps may pin an expected framework revision for drift detection:

- Environment variable: `WAASEYAA_GOLDEN_SHA` (40-char hex or full ref)
- Or project file: `.waaseyaa-golden-sha` (first line only, trimmed)

CI should set one of these and run `bin/waaseyaa-version` (or `php bin/waaseyaa waaseyaa:version`) **without** `--report-only` so merges fail when the lockfile/path checkout does not match policy. Use `bin/waaseyaa-version --strict` for the same semantics in scripts (explicit alias for default behavior).

## Operational command

See `bin/waaseyaa-version` (app) and console command `waaseyaa:version`. They report:

- Resolved `waaseyaa/*` versions from `composer.lock`
- Monorepo Git `HEAD` when dependencies use `path`
- Comparison to golden SHA when configured
- A short drift summary

Options:

- `--json` — machine-readable output for aggregators
- `--strict` — fail on drift when golden SHA is configured; same exit semantics as omitting `--report-only` (documentation / CI clarity only)
- `--report-only` — print drift but exit `0` (transitional CI)

## GraphQL schema contract tests (`waaseyaa/graphql`)

The canonical base is `Waaseyaa\GraphQL\Testing\AbstractGraphQlSchemaContractTestCase` in the `waaseyaa/graphql` split package. Consumers should depend on `waaseyaa/graphql` and extend that class. If the split repository has not yet published `src/Testing/` for a given tag, use a **path** repository to `packages/graphql` in the monorepo (or CI checkout of `waaseyaa/framework`) until split parity catches up—do not duplicate the class in app repos.

## Compatibility matrix

The extension / surface compatibility story remains in [extension-compatibility-matrix.md](./extension-compatibility-matrix.md). This document covers **framework revision identity** only.
