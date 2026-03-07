# GitHub Workflow Governance

## Versioning Model

The Waaseyaa Framework and Minoo (the flagship consumer app) version independently.

- **Framework versions** represent platform contract stability (ingestion envelope, schema registry, ACL substrate, operator diagnostics, CI gates).
- **App versions** (Minoo etc.) represent product feature maturity.
- The framework is the platform; apps are consumers. App versioning is constrained by framework releases, not the reverse.
- The framework is pre-v1. Previous labels such as `v1.6` represented sprint identifiers, not semantic versions. No official v1.0 has been cut. Pre-v1 minor versions may increment indefinitely (v0.7 → v0.8 → v0.9 → v0.10 → …). v1.0 is cut only when platform contracts are formally locked.

## Framework Milestones

| Milestone | Description | Status |
|-----------|-------------|--------|
| v0.7 | SSR path templates stabilized; Admin SPA critical bugs resolved; app developer experience unblocked | Active |
| v0.8 | Default content type (core.note), boot enforcement, ACL baseline, CI versioning gates — platform contracts begin | Future |
| v0.9 | Ingestion envelope, schema registry, namespace rules, RBAC, telemetry, operator diagnostics, onboarding guardrails | Future |
| v0.10 | Feature flags, tenant migration plan — contract evolution and rollout safety finalized before v1.0 lock | Future |
| v1.0 | Platform contracts locked. Ingestion, schema registry, ACL, versioning, and CI — stable and semver-committed | Future |

**Update this table whenever milestones are added, closed, or redescribed.**

## Milestone Narrative Arc

- v0.7 — make the platform usable
- v0.8 — define the platform contract
- v0.9 — expand the platform contract
- v0.10 — stabilize the platform contract
- v1.0 — lock the platform contract

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
