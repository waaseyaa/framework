# Feature Specification: Revision & Audit Provenance

**Mission**: `revision-audit-provenance-01KTWY5V`
**Created**: 2026-06-12
**Status**: Draft
**Tracking**: [#1644](https://github.com/waaseyaa/framework/issues/1644), [#1645](https://github.com/waaseyaa/framework/issues/1645), [#1648](https://github.com/waaseyaa/framework/issues/1648)
**Target release**: v0.1.0-alpha.205

## Overview

The framework cannot answer "who did this." Revisions record when and what changed but never who: the live revision write path has no author column, and the metadata accessor that should expose an author always returns null. The audit log — an OCAP-positioned, append-only record — mis-attributes its most important events: entity writes are attributed to the entity's own `uid` field (usually absent → recorded as account 0), agent-tool activity is hardcoded to account 0, publish-pointer moves produce no audit row at all, and MCP dispatch auditing is wired to an event nothing fires. Separately, the append-only guarantee is builder-level only: raw SQL through the decorator's `query()` can mutate audit rows, while the class documentation implies it cannot.

The consequence: every consumer app re-invents attribution as ordinary entity fields snapshotted per revision (`editor_uid` / `editor_label` patterns), and the audit trail cannot support accountability questions in default wiring. This mission makes the framework record the acting account on revisions and audit events, audits publish-pointer moves, fires the MCP dispatch event, and closes the raw-SQL gap in the append-only guard.

## User Scenarios & Testing

### Primary user story

An editor (or an AI agent acting on behalf of an account) saves a change to a revisionable entity. Later, an administrator reviewing history sees, for each revision: when it was created, the log message, and **which account created it** — and the audit log independently records the same actor for the write. When content is published, the audit log records who published what and when. No application code was written to achieve any of this.

### Acceptance scenarios

1. **Given** an authenticated session, **when** an entity is saved through any standard write path, **then** the new revision records the acting account, and that author is readable back through the revision metadata accessor on load.
2. **Given** a write with no acting account in scope (CLI batch, system bootstrap), **then** the revision and audit rows record the absence distinctly (null), never silently attributing to account 0.
3. **Given** an entity write or delete in an authenticated request, **then** the audit row's actor is the session account — not the entity's own `uid` field value.
4. **Given** an agent tool execution by a known account, **then** the audit row records that account, not 0.
5. **Given** a publish-pointer move (publish, revert), **then** an audit row records the actor, the entity, and the revision transition; this event class is no longer invisible.
6. **Given** an MCP request that dispatches a tool, **then** the MCP dispatch audit event actually fires and is recorded with the acting account.
7. **Given** raw SQL submitted through the audit database decorator attempting UPDATE/DELETE/DROP/ALTER against audit tables, **then** it is rejected with the same error the builder-level guard raises.
8. **Given** a consumer upgrading, **when** existing revisionable tables lack the author column, **then** schema sync adds it additively without data loss; existing revisions read back with a null author.

### Edge cases

- **Anonymous web requests**: the anonymous account (id 0 by sentinel convention) is a real acting context — distinguish "anonymous actor (0)" from "no actor context (null)".
- **Queue/job writes**: jobs run without a session; they record null unless the job carries an explicit acting account.
- **Two schema dialects**: a dormant parallel revision-table spec already contains an author column; this mission must not leave two divergent dialects — the dormant spec is reconciled or explicitly retired in docs.
- **Revisions created by reverts**: the actor is whoever performed the revert, not the original revision's author.
- **Existing consumer attribution fields**: apps with `editor_uid`-style fields keep working; framework attribution is additive alongside them.

## Requirements

### Functional requirements

| ID | Requirement | Status |
|----|-------------|--------|
| FR-001 | Every revision created through the standard save path records the acting account when one is in scope, readable back via the revision metadata accessor; absence is recorded as null, never coerced to 0. | Proposed |
| FR-002 | The acting account is resolved from a request-scoped context (the authenticated session account) without requiring callers to thread it manually; an explicit per-save override is available for non-HTTP contexts. | Proposed |
| FR-003 | The live revision table schema gains an author column via additive schema sync; pre-existing revisions surface a null author. | Proposed |
| FR-004 | Entity lifecycle audit rows record the acting account as actor — never the entity's own `uid` field; absence recorded distinctly from account 0. | Proposed |
| FR-005 | Agent-tool audit rows record the executing account instead of hardcoded 0. | Proposed |
| FR-006 | Publish-pointer moves (publish/revert) produce an audit row carrying actor, entity identity, and the revision transition. | Proposed |
| FR-007 | The MCP endpoint dispatches the MCP-dispatch event on tool invocation so the existing MCP audit listener records it with the acting account. | Proposed |
| FR-008 | The append-only audit database decorator rejects raw SQL UPDATE/DELETE/DROP/ALTER targeting audit tables with the same error as the builder-level guard. | Proposed |
| FR-009 | The dormant parallel revision-table spec is reconciled with the live dialect (single authoritative author column definition) or explicitly retired in the revision system spec. | Proposed |

### Non-functional requirements

| ID | Requirement | Status |
|----|-------------|--------|
| NFR-001 | Attribution adds no more than 5% to median revisionable-save time in the integration test environment (actor resolution is a request-scoped read, not a query). | Proposed |
| NFR-002 | 100% of the four mis-attribution surfaces named in #1645 (entity lifecycle, agent tool, publish pointer, MCP dispatch) have tests pinning correct actor recording. | Proposed |
| NFR-003 | The raw-SQL guard has zero false positives on the audit package's own legitimate operations (insert-only writer, retention prune via raw DB) — proven by the existing audit test suite remaining green. | Proposed |

### Constraints

| ID | Constraint | Status |
|----|-----------|--------|
| C-001 | Schema change is additive-only (new nullable column); no rewrite of existing revision rows; no breaking change to revision read APIs (new metadata is additive). | Accepted |
| C-002 | The audit package's append-only enforcement (alpha.202 decorator design) is strengthened, not redesigned; the retention prune path keeps resolving the raw database. | Accepted |
| C-003 | All charter quality gates apply; ships in v0.1.0-alpha.205 under the CI-gated release flow; CHANGELOG entries under `[Unreleased]`. | Accepted |
| C-004 | Audit event payload/schema changes must remain readable by existing audit query tooling (`AuditEventQuery`); additive fields only. | Accepted |

## Success Criteria

- SC-001: A reference consumer (FNPI) can stop snapshotting `editor_uid`/`editor_label` for NEW revisions and read author from framework metadata instead — demonstrated by reading back the acting account from a revision created through a kernel-booted save.
- SC-002: All four #1645 surfaces record the correct actor in integration tests (NFR-002 at 100%).
- SC-003: An `UPDATE audit_event` via the decorator's raw-SQL path throws; the full audit test suite (including immutability and prune) stays green.
- SC-004: Existing revision history remains fully readable after upgrade (null authors), proven by a migration-shaped test on a pre-upgrade table.
- SC-005: Zero new quality-gate violations; the release ships with CHANGELOG notes covering the new column and audit attribution changes.

## Key Entities

- **Acting account**: the authenticated account in whose name a write occurs — resolved from request scope or supplied explicitly; distinct states: account N, anonymous (0), none (null).
- **Revision metadata**: per-revision record — created time, log message, and now author — readable on revision load.
- **Audit event**: append-only row with event type, actor (`account_uid`, now nullable-distinct), subject, and payload.
- **Publish pointer**: the published-revision reference whose moves now constitute auditable events.

## Assumptions

- The request-scoped account is available where saves occur (the session middleware sets `_account` on every request); non-HTTP contexts default to null absent an explicit override.
- Adding a nullable column via additive sync is supported by the existing schema-sync machinery.
- Consumer cleanup (dropping `editor_uid` snapshots) is per-app work after the release, not part of this mission.
- The MCP endpoint transport bugs (#1635–#1637) are separate; FR-007 only fires the event from the endpoint's dispatch seam and must not depend on fixing those.

## Out of Scope

- Optimistic locking / expected revisions (#1647, next mission).
- Backfilling authors for historical revisions (unknowable).
- Audit retention policy changes; audit UI.
- The MCP endpoint 500/tools-list bugs (#1635, #1636) and OAuth (#1640).
