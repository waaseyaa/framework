# GitHub Workflow Governance

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

## The 5 Workflow Rules

### 1. All work begins with an issue
No code is generated or written without an open GitHub issue. Claude must ask for the issue number before producing code. If no issue exists, Claude must propose creating one and assign it to the appropriate milestone before proceeding.

### 2. Every issue belongs to a milestone
Issues must be assigned to exactly one milestone. Unassigned issues represent incomplete triage. Claude must prompt milestone assignment if an issue lacks one. Use `bin/check-milestones` to surface unassigned issues at any time.

### 3. Milestones define the roadmap
Milestones are the authoritative plan for the repo. Codified context describes philosophy; milestones describe execution. Claude must align all suggestions with the active milestone structure. Do not invent new milestones without explicit discussion.

### 4. PRs must reference issues
Every PR title must include an issue number (e.g. `feat(#42): add SSR path resolution`). PRs without issue references should not be merged. Use the PR template checklist.

### 5. Claude reads milestones before generating work
At session start, `bin/check-milestones` runs automatically. Claude must read the report and flag any drift before beginning implementation work. Claude must also check which milestone is active and align output to it.

## Drift Detection

`bin/check-milestones` runs at every Claude session start via the SessionStart hook. It reports:
- Open issues with no milestone (incomplete triage)
- Open milestones with no open issues (possibly stale)

The script exits 0 always. Output is a warning surface for Claude and contributors, not a CI gate.

The top-level M11 steady-state conformance artifact is [docs/governance/m11-steady-state-conformance-loop.md](../governance/m11-steady-state-conformance-loop.md); governed changes enter that loop through `#999`, and this workflow spec serves as the repo-local front-door proxy for that path. For steady-state drift scans and C17+ logging, use [docs/governance/m11-periodic-drift-scan-protocol.md](../governance/m11-periodic-drift-scan-protocol.md) and the [M11 drift-scan log issue template](../../.github/ISSUE_TEMPLATE/m11-drift-scan-log.md).
