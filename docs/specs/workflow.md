# Workflow governance (forge-neutral change record + design-first)

<!-- Spec reviewed 2026-08-12 - S1-FW-DB-01: workflow authority is forge-neutral. GitHub remains a current adapter and historical evidence locator; stable change records, exact Git objects, and signed evidence are portable authorities. -->
<!-- Spec reviewed 2026-09-05 - #2641: live spec prose that still defers current capability to a closed issue is a nightly warn-only scan (`bin/check-stale-spec-deferrals`), not a PR-diff or preflight gate. `tools/drift-detector.sh` remains the PR-time coupling check. -->

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

### Commit checkpoints and review candidates

Feature-branch commits may be recoverable checkpoints. Acceptance qualifies the
coherent review-candidate head, including required local hooks, the documented
impact-based test plan in [local testing policy](../local-testing-policy.md), and
exact-head hosted CI, rather than every ancestor. Full local suites require a
concrete impact or acceptance reason; they are not a prerequisite for every PR. Ordinary landings use governed
squash auto-merge with pinned-head and combined-state custody checks. The
release-cut path preserves the exact release commit validated by its gates;
rewriting that SHA through a merge operation would break its identity contract.
This is a separate supported boundary, not a general cherry-pick or merge-commit
policy. See [commit-qualification.md](../cookbook/commit-qualification.md).
No per-commit full-suite or default preflight CI jobs are added by this policy.

## Drift Detection

**Specs:** `tools/drift-detector.sh` and manual reads of `docs/specs/` — see [ops/observability/drift-detection.md](../../ops/observability/drift-detection.md). That detector is a PR-diff coupling check. Live spec prose that still defers current capability to an issue that closed *elsewhere* is a different drift class: `bin/check-stale-spec-deferrals` scans body prose only (skipping `<!-- Spec reviewed -->` blocks), flags `ISSUE-CLOSED` present/future-tense deferrals, and runs warn-only on the nightly schedule. It does not belong in `bin/check-pr-preflight`.

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

The canonical and sole release path is the `Cut Release` workflow
(`.github/workflows/release-cut.yml`, `workflow_dispatch` trigger). It validates
SemVer and a non-empty fragment set, deterministically compiles the fragments,
commits, gates, tags, and atomically pushes without an operator-laptop release
script.

```sh
gh workflow run release-cut.yml -f version=v0.1.0-alpha.172
```

Or use the GitHub Actions UI ("Run workflow" → enter version).

The workflow:

1. Validates semver shape (same regex as the legacy script).
2. Guards `v1.0*` tags against missing `release-approvals/v1.0.approved` (same gate `split.yml` runs after the fact — fails earlier).
3. **Gate 1: requires green CI on the release base.** `bin/wait-for-green-ci` polls the Actions API for a completed, successful `ci.yml` run at main HEAD. A red base fails the cut before anything is mutated.
4. Verifies the tag does not already exist (locally or on origin).
5. Runs `bin/check-changelog-shape`, which requires one canonical, empty root
   `## [Unreleased]`, then validates and renders at least one fragment with
   `bin/changelog-fragments`. Both guards run in ordinary CI.
6. Compiles the fragments into `[X.Y.Z] - YYYY-MM-DD`, archives the consumed
   files under `changes/released/<version>/`, and leaves the pending directory
   empty except for its sentinel. The exact rendered bytes feed the annotated
   tag message. It also syncs internal constraints, root lock metadata, and
   `VERSION`. Enumeration is bytewise and has no locale, timezone, checkout-path,
   network, or filesystem-order input.
7. Stages every release mutation (including fragment additions/deletions and
   archive), commits as `github-actions[bot]`, and pushes the release commit to a
   throwaway gate branch (`release-cut/<version>`) — **not** to main.
8. **Gate 2: requires green CI on the exact commit being tagged.** Dispatches `ci.yml` on the gate branch (it has a `workflow_dispatch` trigger for this) and waits for a green conclusion at the release commit's SHA. The skeleton consumer job installs the just-advanced skeleton from that exact monorepo checkout and its split-package paths because the new version cannot exist on Packagist before the tag. The subsequent release-commit push to main uses the same source path so its CI cannot circularly block split publication; ordinary pull requests and non-release main pushes retain the published-release create-project check. Both paths execute post-create setup and `audit-site`.
9. Only then creates the annotated tag and pushes main fast-forward + tag in one **atomic** push using `SPLIT_GITHUB_TOKEN`. The gate branch is deleted either way.

**A tag cannot exist without green Linux CI at that exact SHA.** This is the systemic fix from the alpha.200–202 red-at-tag post-mortem: red jobs (the alpha.200 b1 interface stub, the alpha.202 integration-test misses, the three-release-red `ci/skeleton-create-project` job) can no longer ride into a tagged release, and there is no "the fix will go out in the next cut" path — the cut simply refuses.

Failure recovery is clean by construction: if either gate fails, main is untouched and no tag exists. Fix main (normal commits, normal CI), then re-run the cut with the same version. If the final atomic push is rejected because main advanced during the gate, nothing was tagged — re-run the cut.

### Release workflow timeout budgets

Every workflow job declares `timeout-minutes`; inheriting GitHub's 360-minute
default is forbidden. The limit is a failure boundary, not a runtime target.
Choose it from recent observed duration plus headroom, or from the job's larger
explicit polling/retry budget when that budget dominates the observations.

The release values below were frozen from the five most recent completed runs
available on 2026-09-20. Sparse workflows report their actual smaller sample.
Matrix sample counts are per leaf. A skipped job remains a structural sample
but is not used as evidence that its execution is fast.

| Workflow / job | Timeout | Observed evidence | Rationale |
|---|---:|---|---|
| `release-cut.yml#cut` | 120 min | 4 executions across 3 versions; 24.1 min maximum | Two exact-SHA CI waits can each consume 45 minutes, followed by final release mutation. The limit admits the configured worst case but caps the serialized privileged job at two hours. |
| `split.yml#verify-ci-green` | 60 min | 5 runs; 10.3 min maximum | The underlying green-CI wait has a 45-minute budget. |
| `split.yml#split` | 10 min | 2 to 5 samples per package leaf; 1.5 min maximum | Leaves are independent pushes; ten minutes preserves generous network headroom without letting one leaf occupy the fan-out indefinitely. |
| `split.yml` parity, evidence, main-integrity, and GitHub Release jobs | 10 min | 5 runs each; 0.8 min maximum | These are bounded API, artifact, or repository checks with no long poll. |
| `split.yml#publish-packagist` | 15 min | 5 runs; 6.6 min maximum | Submission is deliberately serialized and jittered, so the limit is more than twice the observed maximum. |
| `split.yml#verify-packagist` | 45 min | 5 runs; 16.2 min maximum | The verifier intentionally owns a 40-minute crawl deadline. |
| `sync-skeleton.yml#sync` | 10 min | 5 runs; 0.2 min maximum | Covers the atomic external-repository update and Packagist submission with network headroom. |
| `github-release.yml#release` | 15 min | 5 runs; 1.2 min maximum | Recovery includes parity and release API operations but no long poll. |
| `packagist-update.yml#discover` / `#verify` | 5 / 20 min | 5 runs; 0.2 / 12.3 min maximum | The verifier has a 12-minute retry budget; discovery is local enumeration. |
| `packagist-recover.yml#recover` | 10 min | 3 executions across 2 versions; 0.7 min maximum | Bounded recovery submissions retain network headroom. |
| `packagist-register.yml#register` | 10 min | 1 failed execution; 0.1 min | There is no successful sample yet. Registration is a single API operation and must fail fast enough for operator retry. |
| `discord-release.yml#notify` | 50 min | 5 runs; 1.2 min maximum | The job intentionally waits up to 45 minutes for exact-SHA Skeleton Smoke evidence before announcing. |

### Packagist submission and verification authority

Packagist push webhooks remain disabled. The release pipeline submits exactly
one authenticated `update-package` request per package after split and parity
gates succeed. A 404 may fall back to `create-package` only in the release
pipeline and the explicit registration workflow, where the main Packagist token
is provided. Skeleton publication and recovery never create packages.

All four submission paths delegate to
`.github/actions/packagist-submit`. That action owns input validation, safe-token
submission, the optional main-token registration fallback, response job-id
capture, and recovery resubmission. Release-pipeline, standalone, and manual
GitHub Release recovery verification delegate to
`.github/actions/packagist-verify`, and targeted Packagist recovery uses the
same P2 visibility implementation. A successful submission means accepted or
queued, never published. Only the exact release tag appearing in P2 metadata
satisfies the publication invariant.

`packagist-update.yml` is manual-only. It is an ad-hoc verifier for an existing
tag and does not run on tag pushes, submit crawls, or participate in release
publication. The ordered release gate remains `split.yml#verify-packagist`.
Both composite actions expose a no-network dry-run mode for contract testing;
release and recovery workflows never enable it.

Non-release jobs use the same rule. Ordinary static and aggregate gates receive
5 to 20 minutes, release-readiness assembly receives up to 30 minutes, existing
nightly and skeleton-smoke budgets remain 45 and 40 minutes, and the governed
auto-merge adapter receives 125 minutes because its explicit merge poll lasts
up to 120 minutes. Any future job must declare a limit and document a long
budget next to the internal wait that requires it.

### Stable workflow job names

Every workflow job declares an explicit `name:` because its visible check name
is an operational and policy interface. Matrix names include the leaf identity
that owns the failure, and visible names are unique across workflows. The
release pipeline and manual recovery path therefore publish distinct contexts:
`Publish GitHub Release (release pipeline)` and
`Publish GitHub Release (manual recovery)`.

The mixed naming surface in `ci.yml` is intentional until the governed ruleset
migration in #3087 Task 7. `ci/<slug>` names identify detailed CI execution and
aggregate lanes, title-case names identify human-facing repository or release
policy checks, and `support/s1-contract` keeps its separate support-contract
namespace. The remaining bare required names, including `composer-policy`,
`check-dead-code`, and `packaged-form`, are compatibility interfaces already
consumed by branch protection. They must not be normalized independently of the
fail-closed ruleset migration that runs old and replacement contexts in
parallel. New jobs must not inherit an implicit job-key or matrix-derived name.

Task 5 of `FW-CI-CHECK-ROSTER-AUDIT-01` added nine `merge/*` contexts that group
the 22 legacy required contexts by owned invariant, with random-order kept
separate from the other PHP behavior checks so its Task 8 cadence decision
cannot weaken ordinary test or coverage protection. Each aggregate uses
`if: always()` and explicitly requires every prerequisite result to equal
`success`; failed, cancelled, skipped, or missing evidence cannot produce a
green decision. Since the Task 7 migration these nine are the live ruleset's
sole required-check interface. A prerequisite may be added behind an existing
aggregate without renaming it, as #2678 added `ci/native-host-contract` behind
`merge/platform-runtime-acceptance`; `tools/ci-check-roster.json` records the
prerequisites, and the legacy baseline below is not extended.

Task 6 adds `ci-roster-live-audit.yml`, scheduled weekly and available by manual
dispatch only. `bin/audit-ci-roster-live` compares the manifest with the live
ruleset integration bindings and the latest check runs for one exact SHA. It
fails on a missing or non-successful stable decision or prerequisite, publishes
the report and CRC026 snapshot as retained artifacts, and reports only labelled
job-wall cost proxies because billed runner minutes are unavailable. GitHub API
availability is therefore outside the ordinary pull-request critical path.

Task 7 uses `bin/project-ci-ruleset` to project the tracked
`main-protection` baseline through exactly three states: the 22 legacy contexts,
the 31-context legacy-plus-stable union, and the nine stable decisions. The
command is a dry run unless `--apply`, the exact ruleset id, a fresh live payload
hash, and an exact evidence SHA at current `main` are all supplied. It rejects non-status drift,
an unexpected predecessor projection, missing or non-green exact-SHA evidence,
and a post-write refetch whose hash differs from the plan. Forward evidence
covers the legacy contexts with their tracked bindings, the nine stable
decisions, and every aggregate prerequisite not already among them, bound to
the GitHub Actions app; each must be a completed success on the evidence SHA
(#2678). Rollback from either forward state restores the complete tracked
22-context payload, byte-identical and never extended with later
prerequisites, and does not depend on green checks. During the bounded migration only those three exact
projections are accepted by the scheduled live audit. Task 9 narrows that
temporary allowance to the final projection.

## Release readiness is not deployment

Merging to `main` proves the Framework candidate through CI; it does not deploy
an application. `.github/workflows/release.yml` is a manual, read-only
**Release Readiness** verifier. It accepts only an exact 40-character commit
SHA reachable from `origin/main`, builds that candidate, records bounded
metadata, and runs the full browser suite. It has no GitHub Environment,
deployment permission, artifact-promotion transport, rollback action, or
publication
authority, or production incident path.

The Framework is a library. Tagging and package publication remain owned by
the separately governed `release-cut.yml`, split, and Packagist workflows.
Application staging, production promotion, rollback, and operator recovery
belong to the consuming application and infrastructure repositories where a
real immutable artifact and external target exist. A Framework workflow must
not claim those operations merely because it builds on a hosted runner or
writes metadata.

The push must use the `SPLIT_GITHUB_TOKEN` PAT, not the default `GITHUB_TOKEN`, because tag pushes by `GITHUB_TOKEN` do **not** trigger downstream workflows. The tag must start `split.yml`, which owns package submission and verification, as well as the separately scoped tag consumers such as skeleton synchronization.

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

Maintainer delivery telemetry follows the same authority rule. GitHub owns its
native events, while off-platform agent review and verification events enter
the governed append-only ledger defined by [delivery-telemetry.md](./delivery-telemetry.md).
DevLake and dashboards are projections of those authorities, never substitutes.
