# Workflow governance (forge-neutral change record + design-first)

<!-- Spec reviewed 2026-08-12 - S1-FW-DB-01: workflow authority is forge-neutral. GitHub remains a current adapter and historical evidence locator; stable change records, exact Git objects, and signed evidence are portable authorities. -->

**Planning and execution** for substantive work follow the **design-first flow**: brainstorm → design/spec in `docs/specs/` → written plan → TDD implementation → code review → verification. Multi-candidate efforts are anchored by a stable, repository-portable **change record** that records scope, work-package breakdown, and descope decisions; every review candidate references it. **`docs/specs/`** remains the contract layer agents read from disk.

GitHub is the current collaboration adapter: issues, pull requests, Actions,
releases, and private reporting may mirror the portable records. Git history,
signed evidence, exact dependency locks, content-addressed artifacts, and the
independent deployment/key/recovery authorities remain usable without GitHub.
No forge account, API, issue number, approval object, environment, or hosted
artifact is a durable Waaseyaa authority.

> **Spec Kitty is retired** (2026-07-06). Do not run `spec-kitty` commands or consult `.kittify/` state. Historical mission artifacts are preserved read-only under `kitty-specs/`; the charter formerly at `.kittify/charter/charter.md` now lives at [`docs/governance/charter.md`](../governance/charter.md).

## Versioning Model

Framework **revision identity** (monorepo Git SHA vs split `waaseyaa/*` packages, golden SHA for apps, `bin/waaseyaa-version`) is documented in [version-provenance.md](./version-provenance.md). Root `composer.json` `"version"` in the monorepo is not a published semver line.

**Per-site consumer audits** (repeatable convergence checklist, artifact location, roster order): [per-site-convergence-audit.md](./per-site-convergence-audit.md).

The Waaseyaa Framework and Minoo (the flagship consumer app) version independently.

- **Framework versions** represent platform contract stability (ingestion envelope, schema registry, ACL substrate, operator diagnostics, CI gates).
- **App versions** (Minoo etc.) represent product feature maturity.
- The framework is the platform; apps are consumers. App versioning is constrained by framework releases, not the reverse.
- The framework passed v1.0 after platform contracts (ingestion envelope, schema registry, ACL, versioning, CI gates) were stabilized through v0.7–v0.12. Post-v1.0 milestones follow semantic intent: minor versions add capabilities (search, revisions, workspaces), v2.0 introduces breaking schema changes.

## Framework Milestones

| Milestone | Description | Status |
|-----------|-------------|--------|
| v0.7 | SSR path templates stabilized; Admin SPA critical bugs resolved; app developer experience unblocked | Closed |
| v0.8 | Default content type (core.note), boot enforcement, ACL baseline, CI versioning gates — platform contracts begin | Closed |
| v0.9 | Ingestion envelope, schema registry, namespace rules, RBAC, telemetry, operator diagnostics, onboarding guardrails | Closed |
| v0.10 | Feature flags, tenant migration plan — contract evolution and rollout safety finalized before v1.0 lock | Closed |
| v0.11 | Ingestion pipeline defaults — envelope schema, validation, error format, logging, CI enforcement | Closed |
| v0.12 | Operator diagnostics & health — CLI health commands, runtime diagnostics, schema drift detection, ingestion health | Closed |
| v1.0 | Platform contracts locked — ingestion, schema registry, ACL, versioning, CI stable | Closed |
| v1.1 | Post-v1.0 stabilization and cleanup | Closed |
| v1.2 | Continued stabilization | Closed |
| v1.3 | GraphQL & cleanup | Closed |
| v1.4 | Remove database-legacy & unify under DBAL | Closed |
| v1.5 | Admin Surface Completion — complete admin-surface package: controllers, host contract, catalog API | Open |
| v1.6 | Search Provider — implement concrete `SearchProviderInterface` (SQLite FTS5); independent with no milestone dependencies | Open |
| v1.7 | Revision System — implement `RevisionableInterface` + `RevisionableStorageInterface`; depends on: v1.4 (DBAL unification) | Open |
| v1.8 | Projects & Workspaces — framework-level project/workspace model and kernel isolation boundaries; depends on: v1.4 (DBAL unification) | Open |
| v1.9 | Production Queue Backend — add Redis or database-backed queue driver for production async | Open |
| v2.0 | Schema Evolution — auto-ALTER tables on field definition changes and generate migrations; depends on: v1.7 (Revision System) | Open |

**Update this table whenever milestones are added, closed, or redescribed.**

## GitHub issues (optional)

GitHub issues are not organized into Track milestones. A standalone issue
(community visibility, Dependabot, templates, or contributor preference) stands
on its own. The **Framework Milestones** table is the semantic capability
narrative; versioned change records are the execution map. Historical GitHub
milestones remain useful context but are not load-bearing workflow authority.

**Dependabot and dependency PRs:** **Pull requests** that only bump dependencies may omit `(#N)` in the title when there is no tracking issue; if there is a chore or security issue, link it per rule #3.

## Milestone Narrative Arc

**Pre-v1 (platform foundation):**
- v0.7 — make the platform usable
- v0.8 — define the platform contract
- v0.9 — expand the platform contract (tenant onboarding, security)
- v0.10 — polish the admin experience
- v0.11 — ingestion pipeline foundation
- v0.12 — operator diagnostics and health

**v1.x (platform capabilities):**
- v1.0 — lock the platform contract
- v1.1–v1.3 — stabilization, GraphQL, cleanup
- v1.4 — unify storage under DBAL
- v1.5 — complete the admin surface
- v1.6 — add search (SQLite FTS5)
- v1.7 — add revision tracking
- v1.8 — multi-project/workspace support
- v1.9 — production-grade queue backend

**v2.x (breaking changes):**
- v2.0 — automatic schema evolution (field-definition diffing, migration generation)

## The 4 Workflow Rules

### Blocking fresh-install cutover invariant

The required `ci/unit-tests` context runs `CutoverFreshInstallSmokeTest` on a clean SQLite database through the real `db:init` path. The smoke must keep `schema:check` green, persist and render an import-derived bundle field from a separate HTTP process, and traverse a freshly-created relationship through SSR. Upgraded fixtures do not substitute for this fresh-install boundary.

### 1. Substantive work begins with a design and a stable change record
Do not drive multi-step implementation from a blank prompt. Multi-candidate
efforts create a versioned change record recording intent, work-package
breakdown, decisions, descopes, and deferrals, then follow the design-first
flow. A forge issue may mirror the record for discussion, but losing the forge
must not lose the audit trail.

### 2. Forge issues are optional tracking mirrors
Not every change needs a forge issue. When used, issues remain discovery and
discussion surfaces and link the portable change-record identifier. The
**Framework milestones** table describes capability intent; versioned change
records are the durable execution map.

### 3. Review candidates must be traceable
Every review candidate records what it delivers, its stable change-record ID,
exact parent and candidate commits, and verification evidence. The GitHub
adapter may additionally use `Closes #N`, `Part of #N`, and its pull-request
template, but those locators do not replace the portable identity.

### 4. Read context before generating work
At session start under an ongoing effort, read the versioned change record,
retained decision trail, and relevant `docs/specs/` contracts. Read the current
forge mirror as supplemental context when it is available.

## Drift Detection

**Specs:** `tools/drift-detector.sh` and manual reads of `docs/specs/` — see [ops/observability/drift-detection.md](../../ops/observability/drift-detection.md).

**Gates:** the complete local/CI gate architecture — one preflight command mirroring CI's fast repo-state gates, one refresh command for governed recorded artifacts, pre-push parity, and semantic roster identity — is specified in [governed-gates.md](governed-gates.md). `php bin/check-pr-preflight` is the command; `tools/preflight-gates.json` is the roster.

## Composer Manifest Policy (Codified + Gated)

`composer.json` consistency is a hard policy enforced by `bin/check-composer-policy` in hooks and CI.

Policy rules:

1. `config.sort-packages` is required and must be `true` in all first-party `composer.json` manifests (CP001).
2. `@dev` constraints for `waaseyaa/*` are forbidden in root `composer.json` and in `packages/*/composer.json` (CP002). The root manifest is published to Packagist as `waaseyaa/framework` and consumers cannot resolve `@dev`. `examples/` and `docs/examples/` are consumer demos that track local path repos at dev-main and may use `@dev`.
3. Wildcard constraints for internal `waaseyaa/*` packages are forbidden everywhere (CP003).
4. `waaseyaa/core` must keep optional observability/dev packages (`waaseyaa/debug`, `waaseyaa/telescope`, `waaseyaa/testing`) out of `require`; they belong in `suggest` (CP004).
5. Cross-package constraints in `packages/*` and `skeleton/composer.json` must include an explicit pre-release floor (alpha/beta/rc/dev), e.g. `^0.1.0-alpha.150`, so Composer cannot resolve a stale sibling missing required methods (CP005).
6. The root `composer.json` (published as `waaseyaa/framework`) uses `self.version` for all `waaseyaa/*` siblings (CP006). `self.version` resolves to `dev-main` against local path repos and to the exact tag version (e.g. `0.1.0-alpha.170`) when crawled by Packagist, giving consumers exact-matching siblings without a release-time rewrite step. `self.version` is forbidden outside root since non-root manifests have no parent metapackage version to bind against.
7. Every package-local `repositories` entry of type `path` must resolve to a `waaseyaa/*` package named in that manifest's `require` or `require-dev`, and every such internal requirement must have the matching path repository (CP007). This keeps package-local installs reproducible in the monorepo without accumulating stale or one-way repository metadata.

> CP006 was filed as #1382 after alpha.170 shipped to Packagist with unresolvable `@dev` constraints in the root artifact.

## Cutting Releases

The canonical release path is the `Cut Release` workflow (`.github/workflows/release-cut.yml`, `workflow_dispatch` trigger). It mirrors what `scripts/release.sh` did locally — validate semver, verify `[Unreleased]` has content, mutate CHANGELOG, commit, tag, push — but runs in CI with no interactive prompts and no operator-on-laptop dependency.

```sh
gh workflow run release-cut.yml -f version=v0.1.0-alpha.172
```

Or use the GitHub Actions UI ("Run workflow" → enter version).

The workflow:

1. Validates semver shape (same regex as the legacy script).
2. Guards `v1.0*` tags against missing `release-approvals/v1.0.approved` (same gate `split.yml` runs after the fact — fails earlier).
3. **Gate 1: requires green CI on the release base.** `bin/wait-for-green-ci` polls the Actions API for a completed, successful `ci.yml` run at main HEAD. A red base fails the cut before anything is mutated.
4. Verifies the tag does not already exist (locally or on origin).
5. Runs `bin/check-changelog-shape`, which requires exactly one canonical
   `## [Unreleased]` heading and rejects near-miss variants, then verifies that
   canonical section has content. The same shape guard runs in
   `composer verify`, so duplicate release-note authorities fail on ordinary
   PR CI before the release workflow is dispatched.
6. Mutates the changelog: renames `[Unreleased]` → `[X.Y.Z] - YYYY-MM-DD`, inserts fresh `[Unreleased]`; syncs internal `waaseyaa/*` constraints in split-package manifests and the create-project skeleton; updates the corresponding path-package `require` metadata embedded in the root `composer.lock`; stamps `VERSION`. The lock sync is local and deterministic: it does not resolve or update third-party packages.
7. Stages every release mutation (`CHANGELOG.md`, `VERSION`, root lock, split-package manifests, and skeleton manifest), commits as `github-actions[bot]`, and pushes the release commit to a throwaway gate branch (`release-cut/<version>`) — **not** to main.
8. **Gate 2: requires green CI on the exact commit being tagged.** Dispatches `ci.yml` on the gate branch (it has a `workflow_dispatch` trigger for this) and waits for a green conclusion at the release commit's SHA. The skeleton consumer job installs the just-advanced skeleton from that exact monorepo checkout and its split-package paths because the new version cannot exist on Packagist before the tag. The subsequent release-commit push to main uses the same source path so its CI cannot circularly block split publication; ordinary pull requests and non-release main pushes retain the published-release create-project check. Both paths execute post-create setup and `audit-site`.
9. Only then creates the annotated tag and pushes main fast-forward + tag in one **atomic** push using `SPLIT_GITHUB_TOKEN`. The gate branch is deleted either way.

**A tag cannot exist without green Linux CI at that exact SHA.** This is the systemic fix from the alpha.200–202 red-at-tag post-mortem: red jobs (the alpha.200 b1 interface stub, the alpha.202 integration-test misses, the three-release-red `ci/skeleton-create-project` job) can no longer ride into a tagged release, and there is no "the fix will go out in the next cut" path — the cut simply refuses.

Failure recovery is clean by construction: if either gate fails, main is untouched and no tag exists. Fix main (normal commits, normal CI), then re-run the cut with the same version. If the final atomic push is rejected because main advanced during the gate, nothing was tagged — re-run the cut.

## Deployment promotion and freezes

Merging to `main` proves deployability through CI; it is not a deployment.
`.github/workflows/release.yml` is manual-dispatch only and requires the exact
40-character SHA of a commit reachable from `origin/main`. A `staging` target
stops after the staging build and full browser sweep. A `production` target
must pass the same staging proof and then enter the separately protected GitHub
production environment before promotion.

Production normally requires the checked-out commit to carry an exact release
tag. An emergency untagged promotion is fail-closed unless the operator selects
the explicit override and supplies an audit justification of at least 20
characters. The deployment script enforces the same rule independently of the
workflow. A deployment freeze is therefore verified by the absence of manual
`Release Pipeline` dispatches; ordinary CI, PR merges, and non-release package
splits create no staging or production environment record.

The push must use the `SPLIT_GITHUB_TOKEN` PAT, not the default `GITHUB_TOKEN`, because tag pushes by `GITHUB_TOKEN` do **not** trigger downstream workflows — and `split.yml` + `packagist-update.yml` are exactly what we need to fire.

**A run location is not an authority.** Release evidence must come from the
declared Linux runner profile and bind the exact candidate, commands, inputs,
and results. A Windows-only pass does not satisfy that profile, and an opaque
hosted green check does not satisfy it without the evidence record. The current
GitHub adapter uses `bin/wait-for-green-ci`; a replacement adapter must enforce
the same machine contract without consulting GitHub.

The legacy `scripts/release.sh` local-release script has been **removed** (alpha.234) — the `Cut Release` workflow is the only supported path. It could not CI-prove the exact release commit the way the workflow's gate branch does (it only enforced Gate 1 on the base via `bin/wait-for-green-ci`), and a local cut on Windows was an active footgun. There is no local fallback: cut releases through CI.

Filed as #1385 after the alpha.171 cut for #1382 surfaced the manual-release friction; CI gates added after the alpha.200–202 red-at-tag incident (2026-06-10).

## Release Tag Parity

Release tags must split to every package repo that is represented under `packages/*/composer.json`.

- Guard script: `bin/check-release-tag-parity`
- Primary enforcement: `.github/workflows/split.yml` — `verify-tag-parity` after the split matrix, then `publish-github-release` (so parity always runs before the monorepo GitHub Release exists)
- Recovery / backfill: `.github/workflows/github-release.yml` (`workflow_dispatch` only; optional parity preflight + release for an existing tag)

This prevents publishing a framework tag where a required split package tag is missing (the failure class that left consumers unable to resolve `waaseyaa/core` when one required package had not been published).

Failure format is machine- and human-readable, including:
- file path
- violated rule id
- current value
- expected value

The top-level M11 post-execution governance baseline is [m11-post-execution-governance-bootstrap.md](./m11-post-execution-governance-bootstrap.md). Governed changes enter that loop through a versioned change record. The current [governed-change issue template](../../.github/ISSUE_TEMPLATE/m11-governed-change.md) may mirror the record for GitHub users but is not the audit front door. The operating loop itself is [m11-steady-state-conformance-loop.md](./m11-steady-state-conformance-loop.md), and steady-state drift scans and C17+ logging use [m11-periodic-drift-scan-protocol.md](./m11-periodic-drift-scan-protocol.md) and the optional [M11 drift-scan log issue template](../../.github/ISSUE_TEMPLATE/m11-drift-scan-log.md).
