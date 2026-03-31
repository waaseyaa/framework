# Waaseyaa Framework Architectural Audit

Date: 2026-03-31

## Goal

Produce a clean, invariant-driven architectural snapshot of `waaseyaa/framework` as it exists today, without remediation work mixed into the audit itself.

Milestone 1 is audit-only. No code fixes are part of this milestone. The only permitted repository edits during Milestone 1 are documentation and metadata updates required to define, track, and preserve the audit.

## Scope

- Repository: `waaseyaa/framework`
- Excluded: downstream consumer apps, convergence audits, and all implementation fixes
- Outputs:
  - `docs/audits/2026-03-31-framework-architectural-audit.md`
  - one GitHub milestone for Milestone 1
  - one umbrella issue for the audit program
  - five pass issues
  - finding issues created beneath the pass issues after each pass rubric is stable

## Audit Principles

- The audit is invariant-driven, not preference-driven.
- The audit records reality first and defers all fixes.
- Findings must be queryable by both architectural concern and framework layer.
- Issues are created only for actionable drift.
- Every finding must cite explicit evidence categories.
- Closed vocabularies are frozen before the audit begins and are not extended during Milestone 1 unless the milestone design itself is revised.

## Milestone 1 Passes

Milestone 1 runs in five deterministic passes:

1. `M1-boundaries`
2. `M1-contracts`
3. `M1-testing`
4. `M1-docs-governance`
5. `M1-dx-tooling`

The milestone is bootstrapped in this order:

1. Create the Milestone 1 GitHub milestone.
2. Create one umbrella issue for the overall audit.
3. Create one pass issue for each Milestone 1 pass.
4. Freeze the audit vocabularies in the audit doc and in the issue body template.
5. Run each pass and only then open finding issues under that pass.

## Concern Model

The concern model is fixed for Milestone 1:

- `boundaries`
- `contracts`
- `testing`
- `docs-governance`
- `dx-tooling`

The audit document is organized by these concerns. The issue inventory mirrors the same concerns and additionally tags findings by layer.

## Layer Model

The layer model follows the framework architecture already documented in `CLAUDE.md`:

- `L0-foundation`
- `L1-core-data`
- `L2-content-types`
- `L3-services`
- `L4-api`
- `L5-ai`
- `L6-interfaces`
- `cross-layer`

`cross-layer` is used only when a finding cannot be cleanly attributed to one layer without obscuring the problem.

## Closed Vocabularies

### Subsystem taxonomy

- `kernel-bootstrap`
- `foundation-infra`
- `core-data`
- `content-types`
- `services`
- `api-routing`
- `ai`
- `interfaces`
- `admin-spa`
- `testing-harness`
- `docs-specs`
- `workflow-governance`
- `build-tooling`
- `package-topology`

Notes:

- `ai` is treated as a true subsystem because the repository contains a distinct Layer 5 package family: `ai-schema`, `ai-agent`, `ai-pipeline`, and `ai-vector`.
- `integration-adapters` is not part of the closed set at audit start. It may be proposed only if the audit reveals a recurring adapter seam that cannot be accurately represented inside the existing subsystem taxonomy.

### Severity

- `critical`
- `high`
- `medium`
- `low`

### Remediation class

- `invariant-break`
- `contract-gap`
- `coverage-gap`
- `governance-drift`
- `docs-drift`
- `tooling-gap`
- `cleanup-candidate`
- `framework-uplift`

### Audit phase

- `M1-boundaries`
- `M1-contracts`
- `M1-testing`
- `M1-docs-governance`
- `M1-dx-tooling`

### Evidence sources

Evidence must be cited using these standardized categories:

- `code`
- `docs`
- `tests`
- `dependency graph`
- `git history`

Each finding must name which evidence categories were used and include the concrete references under those categories.

## Audit Deliverables

Milestone 1 produces two primary artifacts.

### 1. Concern-organized audit document

The audit document is the canonical narrative artifact. It is organized by concern and, for each finding, records:

- invariant being checked
- evidence sources
- finding summary
- affected layers
- affected subsystem
- severity
- remediation class
- notes on later remediation sequencing

### 2. Layer-tagged issue inventory

The issue inventory is the operational artifact in GitHub. Every finding issue must use the fixed metadata shape:

- `Concern`
- `Layer`
- `Subsystem`
- `Severity`
- `Remediation class`
- `Evidence sources`
- `Audit phase`

Issue titles must use this format:

`audit(<concern>)(<layer>): <finding>`

Examples:

- `audit(boundaries)(L4-api): routing leaks higher-layer concerns into access checks`
- `audit(testing)(L1-core-data): entity policy invariants lack negative-path integration coverage`

## Audit Rubric By Pass

Each pass must define and stabilize its rubric before finding issues are opened.

### `M1-boundaries`

Inspect:

- package dependency direction
- layer import discipline
- composition-root containment
- kernel bootstrapping boundaries
- event-based upward communication seams
- package topology drift against the documented layer model

Expected evidence categories:

- `code`
- `dependency graph`
- `docs`
- `git history` when recent churn explains drift

### `M1-contracts`

Inspect:

- public interfaces and extension points
- entity, field, access, routing, and middleware contracts
- spec-to-code agreement
- package public API consistency
- contract asymmetries that are intentional but undocumented

Expected evidence categories:

- `code`
- `docs`
- `tests`
- `git history` when contract drift is historically introduced

### `M1-testing`

Inspect:

- unit, integration, and contract coverage for critical invariants
- negative-path coverage
- test harness quality
- missing regression protection around architectural seams
- tests that verify implementation detail rather than contract

Expected evidence categories:

- `tests`
- `code`
- `docs`
- `git history` when regressions or missing follow-through are visible

### `M1-docs-governance`

Inspect:

- `README.md`
- `CLAUDE.md`
- `AGENTS.md`
- `docs/specs/**`
- milestone tables
- issue workflow expectations
- codified context drift versus actual repository state

Expected evidence categories:

- `docs`
- `code`
- `tests` when documentation promises verification that does not exist
- `git history`

### `M1-dx-tooling`

Inspect:

- Composer scripts and package manifests
- local test and analysis ergonomics
- scaffolding fidelity
- package discoverability
- diagnostics and operator tooling
- developer workflow sharp edges

Expected evidence categories:

- `code`
- `docs`
- `tests`
- `dependency graph`
- `git history`

## GitHub Tracking Model

Milestone 1 creates:

- one milestone: `M1: Framework Architectural Audit`
- one umbrella issue describing the audit contract, scope, outputs, and sequencing
- five pass issues, one per audit phase

No finding issues are created until the relevant pass rubric is confirmed stable.

Finding issues should link back to:

- the umbrella issue
- the pass issue that discovered the finding
- the audit document section when practical

## Recommended Issue Body Shape

Each finding issue should use a stable body structure:

1. Summary
2. Invariant
3. Finding
4. Metadata
5. Evidence
6. Remediation direction
7. Sequencing notes

The metadata section should include the fixed fields and closed vocabularies exactly as frozen in this document.

## Out of Scope

- Fixing boundary violations
- Refactoring packages
- Adding or changing runtime behavior
- Closing test gaps
- Rewriting docs beyond what is needed to preserve the audit record
- Auditing downstream consumer apps
- Consumer convergence planning

## Exit Condition

Milestone 1 is complete when:

- the audit document exists and reflects all five passes
- the milestone exists in GitHub
- the umbrella issue exists
- the five pass issues exist
- every actionable finding discovered during a completed pass has a finding issue using the fixed metadata shape
- no remediation work has been mixed into the audit milestone

## Next Step

After user review of this document:

1. create the Milestone 1 milestone
2. create the umbrella issue
3. create the five pass issues
4. begin with `M1-boundaries`
