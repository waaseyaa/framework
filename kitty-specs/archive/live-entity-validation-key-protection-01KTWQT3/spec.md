# Feature Specification: Live Entity Validation & Key Protection

**Mission**: `live-entity-validation-key-protection-01KTWQT3`
**Created**: 2026-06-12
**Status**: Draft
**Tracking**: [#1643](https://github.com/waaseyaa/framework/issues/1643), [#1646](https://github.com/waaseyaa/framework/issues/1646)
**Target release**: v0.1.0-alpha.204

## Overview

The framework lets application developers declare validation rules on entity fields (required, length, allowed values, numeric ranges), and it ships a validation subsystem — but in a booted application those declared rules are never enforced. Every save accepts whatever it is given. Separately, the stock entity agent tools (the write surface exposed to AI models in-app and over MCP) accept writes to a row's *identity* fields — language code and UUID — so a single hallucinated key in a model's output can corrupt row identity on translatable content rather than merely editing content.

The consequence today is that every consumer application re-implements validation at every write boundary (controllers, services, agent tools) and maintains its own deny-list of protected field names, which each new consumer must rediscover. This mission makes declared validation rules enforce themselves on every save, framework-wide, with no per-app wiring, and makes the stock agent tools refuse identity-field writes unconditionally.

## User Scenarios & Testing

### Primary user story

An application developer declares a numeric field with an allowed range (e.g., a score from 0 to 100). Later, any write path — an HTTP controller, a service, a CLI command, or an AI agent tool — attempts to save an entity with a value outside that range (or of the wrong type). The save is rejected before anything is persisted, with an error that names the field, the violated rule, and the offending value, and the caller can correct and retry. The developer wrote zero validation plumbing.

### Acceptance scenarios

1. **Given** an entity type with a field declaring a numeric range, **when** any caller saves an entity with an out-of-range value, **then** the save fails before persistence with an error identifying the field and the violated range, and the stored data is unchanged.
2. **Given** an entity type with explicitly declared per-field rules (beyond what is derivable from field settings), **when** a save violates one of those declared rules, **then** the save fails the same way — declared rules are honored, not silently ignored.
3. **Given** a valid entity, **when** it is saved through any write path, **then** the save succeeds exactly as before.
4. **Given** an AI agent invoking the stock entity update tool with a values payload that includes `langcode`, `default_langcode`, `uuid`, or another registered identity key, **then** the tool refuses the entire write with an error naming the rejected key(s), no field is modified, and the error is structured so the model can retry without the offending key.
5. **Given** an AI agent invoking the stock entity create tool with identity keys in its values payload, **then** the same refusal applies.
6. **Given** an AI agent submitting a content write that violates a declared field rule, **then** the tool surfaces the validation failure as a structured, correctable error (field, rule, message) rather than persisting bad data or crashing.
7. **Given** a consumer application upgrading to this release, **when** an existing code path performs a save that was previously accepted but violates declared rules, **then** the save now fails — and the release notes document this break and the available opt-out.

### Edge cases

- **Bulk saves**: a multi-entity save reports which entity and field failed; entities before the failing one in the batch must not be silently half-persisted without the failure being reported.
- **Framework-internal saves** (e.g., migrations, system bootstrap writes) must not deadlock on validation they cannot satisfy; the opt-out exists for these, used deliberately and visibly.
- **Translation flows**: creating a translation legitimately targets a specific language. Dedicated translation paths remain responsible for identity handling; the *stock generic* agent tools refuse identity keys unconditionally — translation creation through the generic tools is explicitly out of scope.
- **Numeric id on update**: storage already excludes the primary id key from update field sets as an accident of implementation; this exclusion becomes a documented, deliberate behavior with test coverage.
- **Validation-disabled mode**: when the opt-out is engaged, behavior matches today's (no enforcement) so existing escape hatches stay predictable.

## Requirements

### Functional requirements

| ID | Requirement | Status |
|----|-------------|--------|
| FR-001 | Every entity save through the standard persistence pipeline validates the entity against its declared field rules before persistence; any violation aborts the save with no partial write. | Proposed |
| FR-002 | Numeric fields with declared minimum/maximum settings are enforced as range rules at save time. | Proposed |
| FR-003 | Rules explicitly declared on a field definition are enforced at save time, merged with the rules derived from field settings. | Proposed |
| FR-004 | Validation failures produce an error that identifies, for each violation: the field name, the violated rule, and a human-readable message. | Proposed |
| FR-005 | The stock entity create and update agent tools refuse any values payload containing a registered identity key (id, uuid, revision key, langcode, default_langcode); the refusal rejects the whole write, names the offending key(s), and persists nothing. | Proposed |
| FR-006 | Agent tool writes flow through the same save-time validation as every other write path (single enforcement seam, no tool-private validation fork). | Proposed |
| FR-007 | Agent tools surface validation failures and identity-key refusals as structured tool errors a model can act on (machine-readable field/rule plus message), not as unhandled exceptions. | Proposed |
| FR-008 | Applications can explicitly opt out of save-time validation (framework-level switch and/or per-save), with enforcement ON as the default; the opt-out is documented. | Proposed |

### Non-functional requirements

| ID | Requirement | Status |
|----|-------------|--------|
| NFR-001 | Save-time validation adds no more than 10% to the median entity save time in the integration test environment for a typical content entity (≤ 20 fields). | Proposed |
| NFR-002 | 100% of identity-key write attempts through the stock agent tools are rejected in automated tests covering every registered identity key on both create and update. | Proposed |
| NFR-003 | Validation error output is deterministic for a given entity state (stable ordering of violations), so callers and tests can assert on it. | Proposed |

### Constraints

| ID | Constraint | Status |
|----|-----------|--------|
| C-001 | This is a consumer-breaking change accepted alpha-style: previously-accepted invalid saves will fail after upgrade. The break and the opt-out must be documented in the CHANGELOG under `[Unreleased]` with an upgrade note, following the alpha.200 two-axis precedent. | Accepted |
| C-002 | All charter quality gates apply: full test suite green across the CI matrix, static analysis clean against the committed baseline, composer policy and dead-code gates pass. | Accepted |
| C-003 | Ships in the v0.1.0-alpha.204 release cut; per the release gate, no tag exists without green Linux CI at the tagged commit. | Accepted |
| C-004 | No new validation vocabulary is invented: enforcement uses the rule/constraint model the framework already declares (issues #1643/#1646 document the dormant pieces). | Accepted |

## Success Criteria

- SC-001: A reference consumer application (FNPI venture section) can delete its app-side write-boundary validation and identity-key deny-list with no behavior regression — the framework rejects the same bad writes the app code rejected before.
- SC-002: Declared-rule violations fail saves across all write surfaces (API, CLI, agent tools), demonstrated by integration tests exercising each surface.
- SC-003: Every registered identity key is refused by the stock agent tools on create and update, demonstrated by tests (NFR-002 at 100%).
- SC-004: Valid-save behavior is unchanged: zero new failures in the existing test suite attributable to well-formed data.
- SC-005: The release ships with an upgrade note; zero consumer-reported "silent data corruption via agent tool" paths remain open against the stock tools for identity keys.

## Key Entities

- **Field definition**: the declaration attached to an entity type's field — type, settings (e.g., min/max), and explicitly attached rules. The single source of truth for what valid data means.
- **Identity keys**: the registered entity keys that define a row's identity rather than its content — id, uuid, revision key, langcode, default_langcode.
- **Validation result**: the structured outcome of validating an entity — per-field violations with rule identity and message.
- **Stock entity agent tools**: the framework-shipped generic create/update/read tools exposed to AI models in-app and over MCP.

## Assumptions

- The alpha-style break is pre-approved by the maintainer (sprint plan approved 2026-06-11; alpha.200 set the precedent for breaking changes with CHANGELOG upgrade notes).
- Translation creation does not go through the stock generic agent tools today; refusing identity keys there breaks no supported flow.
- The opt-out default is ON (enforce); only framework-internal escape hatches and explicitly opted-out apps bypass enforcement.
- Consumer cleanup (deleting app-side guards) is a separate per-app activity after the release cut, not part of this mission.

## Out of Scope

- Per-entity-type or per-field *authorization* scoping of agent tool writes (tracked separately as #1638) — this mission protects identity keys only.
- Optimistic locking / expected-revision conflicts (tracked as #1647, planned for a later mission).
- Backfilling or repairing rows already corrupted by past identity-key writes.
- New constraint types beyond range derivation and honoring already-declarable rules.
