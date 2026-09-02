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

### Path `repositories` blocks in split mirrors

Many subpackage `composer.json` files declare `repositories` entries with `"type": "path"` and relative URLs (for example `../foundation`). Those entries exist so **`composer install` works inside a full monorepo checkout** when sibling packages are resolved from disk.

Split subtree repositories on GitHub contain the same manifests. **Cloning a split mirror alone** can leave those `path` URLs pointing at directories that do not exist in that clone. In practice, consumers should install **`waaseyaa/*` from Packagist (or VCS)** and treat internal path blocks as monorepo-only ergonomics; if a standalone clone of a split repo fails to resolve dependencies, remove or override the `repositories` section locally, or depend on the package via Packagist instead of the raw split tree. Future release automation may strip or rewrite `repositories` during split publish if that proves necessary for standalone clones.

## Golden SHA (apps and CI)

Apps may pin an expected framework revision for drift detection:

- Environment variable: `WAASEYAA_GOLDEN_SHA` (40-char hex or full ref)
- Or project file: `.waaseyaa-golden-sha` (first line only, trimmed)

CI should set one of these and run `bin/waaseyaa-version` (or `php bin/waaseyaa waaseyaa:version`) **without** `--report-only` so merges fail when the lockfile/path checkout does not match policy. Use `bin/waaseyaa-version --strict` for the same semantics in scripts (explicit alias for default behavior).

## Operational command

See `bin/waaseyaa-version` (app) and console command `waaseyaa:version`. They report:

- Resolved `waaseyaa/*` versions from `composer.lock`
- Monorepo Git `HEAD` (and the checkout root it was read from) when dependencies use `path`
- Comparison to golden SHA when configured
- A short drift summary

### Path-install topologies and the resolution contract

`ComposerProvenanceReporter` (`packages/cli/src/Provenance/`) binds every
`waaseyaa/*` path install to a Git `HEAD` as follows:

1. **Candidates come only from `composer.lock`.** Each lock entry whose
   `dist.type` is `path` contributes its `dist.url` — the same declaration
   Composer already trusts to symlink code into `vendor/`. Nothing else on the
   filesystem is ever probed.
2. **Targets are resolved literally.** A relative URL is anchored at the
   application root; an absolute URL is used as declared. The target must exist
   and be a directory. Symlinks are resolved so a symlinked install binds to the
   checkout that owns the code. Targets **may sit outside the application root**
   — that is the supported sibling topology below (#2810).
3. **The checkout root is discovered deliberately.** From the resolved target
   the reporter walks up to the nearest directory containing a `.git` entry
   (a directory for a clone, a file for a linked worktree). Git is executed
   exactly once per discovered checkout root, as `git -C <root> rev-parse HEAD`,
   and never against an arbitrary path.
4. **Every path install must bind.** A target that does not exist, is not
   inside a Git checkout, or whose checkout Git cannot read is reported by name
   in the drift summary, and strict mode fails — even when other path installs
   resolved. An unbound install is unproven provenance, not a warning.

Supported layouts:

| Topology | Example `dist.url` | Reported checkout |
|---|---|---|
| In-project (app root is the checkout, or packages vendored inside it) | `packages/foundation` | the application root |
| Sibling monorepo checkout (local-main development) | `../waaseyaa`, `../waaseyaa/packages/*` | the sibling checkout, e.g. `/home/dev/waaseyaa` |

All path installs are expected to resolve to **one** checkout root with one
`HEAD`. The reporter keys its bookkeeping by checkout root, not by `HEAD`: two
clones that happen to sit at the same commit are still two checkouts and are
reported as drift (`multiple distinct Git checkout roots under path installs`,
naming each root and its `HEAD`). A single root has exactly one `HEAD`, so this
also covers the distinct-`HEAD` case. The reporter compares `HEAD`
only — it does not inspect the working tree, so a dirty checkout at the golden
SHA passes. Consumers that need a clean-tree guarantee should assert
`git -C <checkout> status --porcelain` is empty alongside this gate.

Options:

- `--json` — machine-readable output for aggregators
- `--strict` — fail on drift when golden SHA is configured; same exit semantics as omitting `--report-only` (documentation / CI clarity only)
- `--report-only` — print drift but exit `0` (transitional CI)

## GraphQL schema contract tests (`waaseyaa/graphql`)

The canonical base is `Waaseyaa\GraphQL\Testing\AbstractGraphQlSchemaContractTestCase` in the `waaseyaa/graphql` split package. Consumers should depend on `waaseyaa/graphql` and extend that class. If the split repository has not yet published `src/Testing/` for a given tag, use a **path** repository to `packages/graphql` in the monorepo (or CI checkout of `waaseyaa/framework`) until split parity catches up—do not duplicate the class in app repos.

## Compatibility matrix

The extension / surface compatibility story remains in [extension-compatibility-matrix.md](./extension-compatibility-matrix.md). This document covers **framework revision identity** only.

## Tagged-release evidence

The deterministic SBOM, monorepo-to-split provenance, immutable workflow-input,
and GitHub Release retention contract is defined in
[release-evidence.md](./release-evidence.md). Consumer build and deployed
identity remain consumer-owned evidence layered on top of that framework
record.
