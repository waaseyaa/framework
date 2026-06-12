# Feature Specification: Optimistic Locking

**Mission**: `optimistic-locking-01KTXCHY`
**Created**: 2026-06-12
**Status**: Draft
**Tracking**: [#1647](https://github.com/waaseyaa/framework/issues/1647)
**Target release**: v0.1.0-alpha.207

## Overview

Concurrent saves are silent last-write-wins everywhere in the stack. Two writers who read the same entity and save overlapping fields never learn about each other: the second save unconditionally overwrites the first, and on non-revisionable types the first writer's work is simply gone. The framework's agent tools invite exactly this dual-writer pattern — a human editing in the admin while an agent applies a requested change — and neither side can even express "I am updating the version I read." One mitigating mechanic already exists and must be preserved: both read-modify-write surfaces set only supplied fields onto a freshly loaded head, so concurrent edits to disjoint fields merge cleanly today.

This mission adds opt-in conflict detection: a caller may state the revision it read, and a save against a moved head is refused with a structured conflict error carrying enough information to re-read, re-diff, or surface a stale-edit warning. Saves that don't state an expectation behave exactly as today.

## User Scenarios & Testing

### Primary user story

An editor opens an entity for editing (reading revision R5). Meanwhile an agent updates the same entity (creating R6). When the editor saves with the expectation "I edited R5," the save is refused with a conflict that names the expected and actual revisions; the UI re-reads, shows what changed, and lets the editor merge deliberately — instead of silently reverting the agent's work.

### Acceptance scenarios

1. **Given** a save that states expected revision R, **when** the entity's current revision is still R, **then** the save proceeds normally.
2. **Given** a save that states expected revision R, **when** the current revision has moved past R, **then** the save is refused before any write, with a structured conflict identifying the entity, the expected revision, and the current revision.
3. **Given** a save with no stated expectation, **then** behavior is byte-identical to today (last-write-wins, disjoint-field merge preserved).
4. **Given** an agent invoking the entity update tool with an expected revision, **when** the head moved, **then** the tool returns a structured, machine-correctable conflict error (the agent can re-read and retry); **when** the head did not move, the update applies.
5. **Given** an API client updating an entity with a stated expectation (HTTP conditional-update semantics), **when** the head moved, **then** the API responds with the conflict status and a body naming expected/actual; **when** it did not move, the update applies.
6. **Given** a non-revisionable entity type, **then** an expectation can still be stated and checked against the framework's change marker for that type (or is cleanly rejected as unsupported if no marker exists — decided at plan time, documented either way).

### Edge cases

- **Conflict check vs write atomicity**: the check must not be a TOCTOU window — verify-and-write must be atomic with respect to competing saves (transaction or guarded update).
- **Revision creation paths beyond save()**: rollback/translation saves either support the expectation or explicitly reject it — never silently ignore a stated expectation.
- **Stating an expectation for a new (unsaved) entity** is a caller error with a clear message.
- **The agent tool's dry-run** reports the conflict the same way a real call would.
- **Disjoint-field merge**: stating an expectation must not break the existing only-supplied-fields mechanic for the success path.

## Requirements

### Functional requirements

| ID | Requirement | Status |
|----|-------------|--------|
| FR-001 | A caller can state an expected revision on a save through the standard persistence pipeline; the save is refused when the entity's current revision differs, before any write occurs. | Proposed |
| FR-002 | The refusal is a structured conflict carrying entity type, id, expected revision, and current revision. | Proposed |
| FR-003 | Saves without a stated expectation behave exactly as before (opt-in feature; no default behavior change). | Proposed |
| FR-004 | The conflict check is race-safe: two concurrent saves stating the same expectation cannot both succeed. | Proposed |
| FR-005 | The stock entity update agent tool accepts an expected revision argument and surfaces conflicts as structured, machine-correctable tool errors; dry-run reports conflicts identically. | Proposed |
| FR-006 | The JSON:API update endpoint accepts an expected revision (conditional-update semantics) and responds with a conflict status + structured body on mismatch. | Proposed |
| FR-007 | Revision-creating paths that cannot honor an expectation reject it explicitly rather than ignoring it. | Proposed |
| FR-008 | The current revision identifier is readable wherever a caller needs to form an expectation (entity reads, tool reads, API reads expose it). | Proposed |

### Non-functional requirements

| ID | Requirement | Status |
|----|-------------|--------|
| NFR-001 | The no-expectation path adds zero queries and no measurable overhead (pinned: same query count as before). | Proposed |
| NFR-002 | A concurrency test demonstrates FR-004 (interleaved expected-revision saves; exactly one winner). | Proposed |
| NFR-003 | Conflict errors are deterministic and assertable (stable shape, no timing-dependent content beyond the revision ids). | Proposed |

### Constraints

| ID | Constraint | Status |
|----|-----------|--------|
| C-001 | Additive API only: new optional parameters/arguments; no signature breaks; no schema changes beyond what revision tracking already provides. | Accepted |
| C-002 | The disjoint-field merge mechanic is preserved on the success path (pinned by test). | Accepted |
| C-003 | All charter quality gates; ships in v0.1.0-alpha.207 under the CI-gated release flow; CHANGELOG under `[Unreleased]`. | Accepted |
| C-004 | Builds on the Mission 1/2 seams (SaveContext, structured tool errors, revision metadata) — no parallel vocabulary. | Accepted |

## Success Criteria

- SC-001: The dual-writer scenario (human + agent overlapping edits) produces a surfaced conflict instead of a silent revert — demonstrated end-to-end through the agent tool against a kernel-booted repository.
- SC-002: A consumer can implement an approve-time staleness check using only framework primitives (read current revision → state expectation → handle conflict), demonstrated in the quickstart.
- SC-003: Zero behavior change for all existing callers (full suite green with no test modifications beyond additions).
- SC-004: The concurrency pin (NFR-002) is green in CI.

## Key Entities

- **Expected revision**: the caller's statement of the revision it read.
- **Conflict**: the structured refusal — entity identity, expected, current.
- **Change marker**: the per-save identifier that moves when an entity changes (revision id on revisionable types; plan decides the non-revisionable answer).

## Assumptions

- Revisionable types have a monotonic per-entity revision identifier readable at save time (revision_id).
- The agent-tool error surface from Mission 1 (structured error payloads) is the vehicle for tool conflicts.
- Consumer adoption (FNPI dual-writer surfaces) happens after the release; this mission ships the primitive.

## Out of Scope

- Pessimistic locks, lock leases, or edit presence indicators.
- Automatic merge/rebase of conflicting edits.
- Admin SPA UI for conflict resolution (consumer/UI work).
- Offline/long-lived draft reconciliation.
