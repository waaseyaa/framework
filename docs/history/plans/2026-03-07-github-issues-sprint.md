# Waaseyaa Platform Defaults — GitHub Issues Sprint

**Branch:** main
**Date:** 2026-03-07
**Milestone mapping:** v0.1-defaults (MVP), v0.2-onboarding, v0.3-migrations, v0.1-release-approval

Copy each issue below directly into GitHub Issues UI or API.

---

## Labels to Create First

| Label | Color | Description |
|---|---|---|
| `defaults` | `#0075ca` | Platform default types and manifests |
| `infra` | `#e4e669` | Infrastructure and platform internals |
| `schema` | `#d93f0b` | Schema changes and registry |
| `onboarding` | `#0e8a16` | Onboarding flow and first-run UX |
| `security` | `#b60205` | Security, RBAC, encryption |
| `operator-ux` | `#5319e7` | Operator-facing diagnostics and UX |
| `migration` | `#fbca04` | Migration plans and rollback |
| `ci` | `#1d76db` | CI/CD workflows and gates |
| `docs` | `#c5def5` | Documentation |
| `versioning` | `#0052cc` | Versioning policy and release gates |
| `p0` | `#b60205` | Priority 0 — must ship |
| `p1` | `#fbca04` | Priority 1 — should ship |
| `p2` | `#c5def5` | Priority 2 — nice to have |

---

## Milestones to Create

| Milestone | Description | Due |
|---|---|---|
| `v0.1-defaults` | MVP: boot validation, core.note, CI gate | Sprint 1 end (week 3) |
| `v0.2-onboarding` | Onboarding UX, quickstart, API examples | Sprint 2 end (week 5) |
| `v0.3-migrations` | Migration tooling and tenant rollback | Sprint 3 end (week 6) |
| `v0.1-release-approval` | Checklist for eventual v1.0 (kept, not executed) | TBD — owner authorized |

---

## Issues

---

### ISSUE-01: Default content type `core.note` — schema and manifest

**Labels:** `defaults` `schema` `infra` `versioning` `p0`
**Milestone:** `v0.1-defaults`
**Effort:** M
**Priority:** P0
**Dependencies:** ISSUE-10 (schema registry), ISSUE-09 (boot validation CI), ISSUE-15 (VERSIONING.md)

**Description:**

Ship a minimal built-in content type `core.note` to guarantee the platform always has a valid content type at boot. This type must be generic, stable, and non-opinionated. The manifest must include the `project_versioning` block per the Pre-v1 Continuation Rule.

**Acceptance Criteria:**

- [ ] `defaults/core.note.yaml` manifest exists with all required fields
- [ ] `defaults/core.note.schema.json` JSON Schema (draft-07) exists
- [ ] `project_versioning` block present and valid in both files
- [ ] Boot validation passes when only `core.note` exists
- [ ] Type cannot be deleted via API; can be disabled by tenant admin with audit log
- [ ] Default ACLs applied: read = tenant.member + tenant.admin, write = tenant.admin only
- [ ] CI manifest conformance check passes

**Implementation Tasks:**

- [ ] Write `defaults/core.note.yaml` (manifest + project_versioning)
- [ ] Write `defaults/core.note.schema.json`
- [ ] Implement API guard: block DELETE, allow disable with audit log entry
- [ ] Add unit test: schema validates a valid note payload
- [ ] Add unit test: schema rejects a payload missing `title`
- [ ] Add boot validation test: passes when `core.note` is the only registered type
- [ ] Add CI check: manifest contains `project_versioning` block
- [ ] Add `defaults/README.md`
- [ ] Link from quickstart docs

**Notes:**

Example valid payload:
```json
{
  "tenant_id": "acme",
  "title": "My First Note",
  "body": "Hello, Waaseyaa."
}
```

Default ACL snippet (from manifest):
```yaml
access_control:
  read: [tenant.member, tenant.admin, platform.admin]
  write: [tenant.admin, platform.admin]
  delete: []
```

**Status:** Manifests complete. API guard, tests, and docs pending.

---

### ISSUE-02: Enforce at least one content type at platform boot

**Labels:** `defaults` `infra` `ci` `p0`
**Milestone:** `v0.1-defaults`
**Effort:** S
**Priority:** P0
**Dependencies:** ISSUE-01

**Description:**

The platform must refuse to boot (or emit a critical diagnostic) if zero content types are registered. This prevents an invalid state where no content can be created.

**Acceptance Criteria:**

- [ ] Boot sequence checks for at least one registered content type
- [ ] If zero types: emits `DEFAULT_TYPE_MISSING` error with remediation steps, halts boot
- [ ] If `core.note` is disabled and no other type exists: emits `DEFAULT_TYPE_DISABLED` warning
- [ ] Boot validation CI test added

**Implementation Tasks:**

- [ ] Add boot check in kernel boot sequence
- [ ] Emit structured error `DEFAULT_TYPE_MISSING` with remediation message
- [ ] Emit warning `DEFAULT_TYPE_DISABLED` when all types are disabled
- [ ] Unit test: boot fails with zero types
- [ ] Unit test: boot succeeds with one disabled type + one active type
- [ ] CI job: boot validation test on every push to main

**Notes:**

Diagnostic messages:
```
[CRITICAL] DEFAULT_TYPE_MISSING: No content types registered.
  Remediation: Ensure core.note is not disabled, or register a custom type.

[WARNING] DEFAULT_TYPE_DISABLED: All content types are disabled.
  Disabled by: tenant:acme at 2026-03-07T12:00:00Z
  Remediation: Re-enable a type via admin UI or CLI: waaseyaa type:enable core.note
```

---

### ISSUE-03: Default content type lifecycle — disable vs delete policy

**Labels:** `defaults` `infra` `p0`
**Milestone:** `v0.1-defaults`
**Effort:** S
**Priority:** P0
**Dependencies:** ISSUE-01

**Description:**

Define and implement the lifecycle rules for built-in types: they cannot be deleted via API, but can be disabled per tenant. Disabling must produce an audit log entry.

**Acceptance Criteria:**

- [ ] DELETE request to `core.note` returns 403 with error code `DEFAULT_TYPE_NOT_DELETABLE`
- [ ] PATCH/disable request succeeds and creates audit log entry with actor, timestamp, type
- [ ] Audit log entry queryable via CLI: `waaseyaa audit:log --type=core.note`
- [ ] UI shows disabled types with badge "disabled (built-in)"
- [ ] Re-enable works: PATCH with `status: active`

**Implementation Tasks:**

- [ ] Add API guard in entity type controller
- [ ] Add audit log entry on disable/enable
- [ ] Add CLI commands: `waaseyaa type:enable <id>` and `waaseyaa type:disable <id>`
- [ ] Unit test: DELETE returns 403
- [ ] Unit test: disable produces audit log
- [ ] Unit test: re-enable works

---

### ISSUE-04: Default access control — baseline roles and field-level ACL

**Labels:** `defaults` `security` `schema` `p0`
**Milestone:** `v0.1-defaults`
**Effort:** M
**Priority:** P0
**Dependencies:** ISSUE-01

**Description:**

Implement default ACLs for `core.note`: read access for all tenant members, write access for tenant admins only, field-level restrictions on system fields. Admin override rules: platform.admin bypasses all tenant-level restrictions.

**Acceptance Criteria:**

- [ ] `tenant.member` can read all non-system fields
- [ ] `tenant.member` cannot write any field
- [ ] `tenant.admin` can read and write non-system fields
- [ ] `tenant.admin` cannot write system fields (id, uuid, tenant_id, created_at, updated_at)
- [ ] `platform.admin` can read and write all fields
- [ ] Anonymous user cannot read or write any field
- [ ] Access policy registered via `#[PolicyAttribute]` on `NoteAccessPolicy`
- [ ] Field access policy co-implemented on same class

**Implementation Tasks:**

- [ ] Create `NoteAccessPolicy` implementing `AccessPolicyInterface & FieldAccessPolicyInterface`
- [ ] Register via `#[PolicyAttribute(entityType: 'core.note')]`
- [ ] Unit tests: one per role × operation combination (minimum 8 tests)
- [ ] Integration test: API call with each role returns correct HTTP status

---

### ISSUE-05: Onboarding flow — first content type creation guardrail

**Labels:** `onboarding` `defaults` `p1`
**Milestone:** `v0.2-onboarding`
**Effort:** M
**Priority:** P1
**Dependencies:** ISSUE-01, ISSUE-04

**Description:**

When a new tenant logs in and has no custom content types, the admin UI must guide them to either use `core.note` or create a custom type. Provide guardrails so they cannot accidentally have zero active types.

**Acceptance Criteria:**

- [ ] Admin UI shows "Get started" prompt when no custom types exist
- [ ] Prompt offers two paths: "Use Note (built-in)" or "Create custom type"
- [ ] If tenant disables `core.note` with no replacement, warn with `DEFAULT_TYPE_DISABLED` before confirming
- [ ] Quickstart guide linked from the prompt

**Implementation Tasks:**

- [ ] Add onboarding state detection to admin SPA
- [ ] Add "Get started" UI component
- [ ] Add warning dialog on `core.note` disable when no other type exists
- [ ] Write onboarding UX copy (en.json i18n entries)
- [ ] Add E2E test: new tenant sees onboarding prompt
- [ ] Add E2E test: warning appears on last-type disable

---

### ISSUE-06: Ingestion pipeline defaults — envelope format and validation

**Labels:** `defaults` `infra` `schema` `p1`
**Milestone:** `v0.2-onboarding`
**Effort:** M
**Priority:** P1
**Dependencies:** ISSUE-01

**Description:**

Define the canonical ingestion envelope format for `core.note` and implement strict validation. Payloads missing required provenance fields must be rejected, not silently dropped.

**Acceptance Criteria:**

- [ ] Ingestion envelope version `"1"` documented and implemented
- [ ] Required provenance fields: `source`, `ingested_at`
- [ ] Validation mode `strict`: invalid payloads rejected with structured error
- [ ] Error response includes field path, error code, and human message
- [ ] Valid payload ingested and persisted as `core.note` entity

**Implementation Tasks:**

- [ ] Define envelope schema (extend `core.note.schema.json`)
- [ ] Implement ingestion validator for `core.note`
- [ ] Add error codes: `INVALID_ENVELOPE`, `MISSING_PROVENANCE`, `SCHEMA_VIOLATION`
- [ ] Unit tests: valid payload accepted, invalid rejected with correct error
- [ ] Integration test: end-to-end ingest via API

**Notes:**

Example ingestion envelope:
```json
{
  "envelope_version": "1",
  "source": "api:import-script",
  "ingested_at": "2026-03-07T12:00:00Z",
  "payload": {
    "tenant_id": "acme",
    "title": "Imported Note",
    "body": "Content here."
  }
}
```

---

### ISSUE-07: Operator diagnostics defaults — error codes and remediation steps

**Labels:** `operator-ux` `defaults` `infra` `p1`
**Milestone:** `v0.2-onboarding`
**Effort:** S
**Priority:** P1
**Dependencies:** ISSUE-02

**Description:**

Define the canonical set of operator-facing diagnostic error codes for default-related failures. Each code must have a structured message, a telemetry event, and suggested remediation steps.

**Acceptance Criteria:**

- [ ] Error codes defined and documented: `DEFAULT_TYPE_MISSING`, `DEFAULT_TYPE_DISABLED`, `UNAUTHORIZED_V1_TAG`, `TAG_QUARANTINE_DETECTED`, `MANIFEST_VERSIONING_MISSING`
- [ ] Each code produces a structured log entry with: code, message, context, remediation
- [ ] Boot diagnostic report shows schema versions and compatibility status
- [ ] Telemetry event emitted for each diagnostic

**Implementation Tasks:**

- [ ] Define `DiagnosticCode` enum or constants
- [ ] Implement structured log formatter
- [ ] Add boot diagnostic report generator
- [ ] Unit tests: each code produces correct log entry format
- [ ] Document in operator guide

**Notes:**

| Code | Trigger | Remediation |
|---|---|---|
| `DEFAULT_TYPE_MISSING` | Zero content types at boot | Enable `core.note` or register a custom type |
| `DEFAULT_TYPE_DISABLED` | All types disabled | Re-enable via admin UI or CLI |
| `UNAUTHORIZED_V1_TAG` | v1.0 tag without approval | Open release-quarantine issue, notify @jonesrussell |
| `TAG_QUARANTINE_DETECTED` | Existing v1.0 tag found | Follow VERSIONING.md Section 2 |
| `MANIFEST_VERSIONING_MISSING` | Manifest lacks project_versioning | Add block per VERSIONING.md Section 3 |

---

### ISSUE-08: Namespace and partitioning defaults — core namespace reservation

**Labels:** `defaults` `infra` `schema` `p1`
**Milestone:** `v0.1-defaults`
**Effort:** S
**Priority:** P1
**Dependencies:** ISSUE-01

**Description:**

Reserve the `core.` namespace for built-in types. Extensions and tenants must be prevented from registering types or entities using the `core.` prefix.

**Acceptance Criteria:**

- [ ] Attempt to register a type with `core.` prefix by extension fails with `NAMESPACE_RESERVED`
- [ ] Attempt to register a type with `core.` prefix by tenant fails with `NAMESPACE_RESERVED`
- [ ] `platform.admin` may register `core.*` types (for future built-ins)
- [ ] Namespace rules documented in `defaults/README.md`

**Implementation Tasks:**

- [ ] Add namespace guard in entity type registration
- [ ] Add error code `NAMESPACE_RESERVED`
- [ ] Unit tests: extension and tenant attempts rejected, platform.admin allowed
- [ ] Update `defaults/README.md`

---

### ISSUE-09: Boot validation CI job — schema conformance and versioning gate

**Labels:** `ci` `defaults` `versioning` `p0`
**Milestone:** `v0.1-defaults`
**Effort:** M
**Priority:** P0
**Dependencies:** ISSUE-01, ISSUE-15

**Description:**

Add a CI job that runs on every push to `main` and on every PR. It must: (1) validate every manifest under `defaults/` has a `project_versioning` block, (2) validate JSON Schema files are well-formed, (3) run boot validation (zero-types check), and (4) validate no unauthorized `v1.0` tag exists.

**Acceptance Criteria:**

- [ ] CI fails if any `defaults/*.yaml` lacks `project_versioning`
- [ ] CI fails if any `defaults/*.schema.json` is not valid JSON Schema draft-07
- [ ] CI fails if boot validation test fails
- [ ] CI fails if a `v1.0*` tag exists without `release-approvals/v1.0.approved`
- [ ] CI passes on a clean main branch

**Implementation Tasks:**

- [ ] Create `.github/workflows/release-gate.yml` (done)
- [ ] Add manifest conformance check step (done in release-gate.yml)
- [ ] Add JSON Schema validation step (use `ajv-cli` or equivalent)
- [ ] Add boot validation PHPUnit test run step
- [ ] Add unauthorized tag detection step (done in release-gate.yml)
- [ ] Update `split.yml` with version gate guard (done)

**Status:** CI workflows created. PHPUnit boot validation test pending.

---

### ISSUE-10: Schema registry defaults — storage and compatibility rules

**Labels:** `schema` `infra` `p1`
**Milestone:** `v0.1-defaults`
**Effort:** M
**Priority:** P1
**Dependencies:** ISSUE-01

**Description:**

Define the schema registry: where schemas are stored, how they are versioned, and what compatibility rules apply pre- and post-v1.0.

**Acceptance Criteria:**

- [ ] Schemas stored under `defaults/` for built-ins; custom schemas in config sync directory
- [ ] Schema version tracked per manifest
- [ ] Pre-v1: compatibility is `liberal` (breaking allowed with migration task)
- [ ] Post-v1: compatibility is `strict` (semantic versioning, breaking = major bump)
- [ ] CLI command `waaseyaa schema:list` shows registered schemas with versions

**Implementation Tasks:**

- [ ] Define schema registry interface
- [ ] Implement file-based registry loading from `defaults/`
- [ ] Add `compatibility` field to manifest format (done in core.note.yaml)
- [ ] Add CLI command `waaseyaa schema:list`
- [ ] Unit tests: registry loads `core.note` schema correctly
- [ ] Document compatibility rules in `VERSIONING.md` Section 4 (done)

---

### ISSUE-11: Telemetry and audit defaults — required fields and retention

**Labels:** `defaults` `security` `infra` `p1`
**Milestone:** `v0.2-onboarding`
**Effort:** S
**Priority:** P1
**Dependencies:** ISSUE-04

**Description:**

Define required audit fields for all entity operations on built-in types and set the default retention policy.

**Acceptance Criteria:**

- [ ] Every entity write produces audit entry with: actor, action, entity_id, tenant_id, timestamp
- [ ] Audit log queryable via CLI: `waaseyaa audit:log --entity-type=core.note`
- [ ] Default retention: 90 days (configurable per tenant)
- [ ] Replay metadata included: envelope_version, source, ingested_at (for ingested entities)

**Implementation Tasks:**

- [ ] Define `AuditEntry` value object
- [ ] Hook audit log into entity storage write path
- [ ] Add retention config option to `config/waaseyaa.php`
- [ ] Add CLI query command
- [ ] Unit tests: write produces audit entry with all required fields

---

### ISSUE-12: Security defaults — RBAC, encryption at rest, secrets handling

**Labels:** `security` `defaults` `p1`
**Milestone:** `v0.1-defaults`
**Effort:** M
**Priority:** P1
**Dependencies:** ISSUE-04

**Description:**

Define security defaults for built-in types: encryption at rest for sensitive fields, RBAC enforcement, and secrets handling policy.

**Acceptance Criteria:**

- [ ] `body` field of `core.note` not encrypted by default (no PII); tenant can opt-in
- [ ] RBAC enforced at API layer: all requests authenticated before entity access
- [ ] No secrets stored in manifests or default configs
- [ ] Secrets sourced from environment variables only
- [ ] Security defaults documented in `docs/specs/security-defaults.md`

**Implementation Tasks:**

- [ ] Audit `core.note` fields for PII risk (document findings)
- [ ] Verify RBAC middleware runs before entity controller for all routes
- [ ] Add `.env.example` with all required environment variables
- [ ] Add CI check: no secrets in `defaults/` files
- [ ] Document security defaults in a new `docs/specs/security-defaults.md`

---

### ISSUE-13: Feature flags and toggles — safe enable/disable of default type behavior

**Labels:** `defaults` `infra` `p2`
**Milestone:** `v0.3-migrations`
**Effort:** S
**Priority:** P2
**Dependencies:** ISSUE-03

**Description:**

Provide a clean mechanism for tenants to disable `core.note` behavior safely, including per-tenant feature flags and a fallback guardrail.

**Acceptance Criteria:**

- [ ] CLI: `waaseyaa type:disable core.note --tenant=acme` with confirmation prompt
- [ ] CLI: `waaseyaa type:enable core.note --tenant=acme`
- [ ] Disable blocked if no replacement type is active (with `--force` override)
- [ ] Audit log entry on every toggle

**Implementation Tasks:**

- [ ] Implement `TypeToggleCommand` CLI command
- [ ] Add `--force` flag with warning
- [ ] Add fallback guardrail check
- [ ] Unit tests: toggle commands produce audit log, guardrail fires correctly

---

### ISSUE-14: Migration plan — existing tenants with no default type

**Labels:** `migration` `defaults` `p1`
**Milestone:** `v0.3-migrations`
**Effort:** M
**Priority:** P1
**Dependencies:** ISSUE-01, ISSUE-03

**Description:**

Provide a migration path for existing deployments that have no `core.note` type registered. The migration must be safe, reversible, and auditable.

**Acceptance Criteria:**

- [ ] Migration CLI detects tenants with zero content types
- [ ] Migration offers: register `core.note`, map to existing type, or skip with warning
- [ ] Rollback: re-disable `core.note` if migrated in error
- [ ] Smoke test: migration CLI runs end-to-end without errors
- [ ] Versioning note: pre-v1 migration is best-effort; post-v1 migration is required with docs

**Implementation Tasks:**

- [ ] Add `waaseyaa migrate:defaults` CLI command
- [ ] Add detection query for tenants with zero types
- [ ] Add interactive prompts and `--yes` flag for automation
- [ ] Add rollback command: `waaseyaa migrate:defaults --rollback`
- [ ] Write smoke test
- [ ] Document in migration guide

---

### ISSUE-15: VERSIONING.md and release quarantine workflow

**Labels:** `versioning` `docs` `ci` `p0`
**Milestone:** `v0.1-defaults`
**Effort:** S
**Priority:** P0
**Dependencies:** ISSUE-09

**Description:**

Create `VERSIONING.md` as the authoritative versioning policy. Define the Pre-v1 Continuation Rule, the quarantine process for unauthorized `v1.0` tags, and the owner approval workflow.

**Acceptance Criteria:**

- [ ] `VERSIONING.md` exists at repo root with all 7 sections
- [ ] `release-quarantine` issue template exists
- [ ] `release-approval` issue template exists
- [ ] All three versioning rules codified verbatim
- [ ] Audit log section present (initially empty)
- [ ] CI references `VERSIONING.md` in error messages

**Status:** Done. VERSIONING.md and all templates created.

---

### ISSUE-16: CI gating — block v1.0 tag creation without owner approval

**Labels:** `ci` `versioning` `p0`
**Milestone:** `v0.1-defaults`
**Effort:** M
**Priority:** P0
**Dependencies:** ISSUE-15

**Description:**

Implement CI enforcement of the Pre-v1 Continuation Rule. No `v1.0` tag may be created on the monorepo or any split package repo without the `release-approvals/v1.0.approved` sentinel file being present.

**Acceptance Criteria:**

- [ ] `release-gate.yml` workflow triggers on any `v1.0*` tag push
- [ ] Fails with `UNAUTHORIZED_V1_TAG` if `release-approvals/v1.0.approved` absent
- [ ] Passes if approval artifact present and valid format
- [ ] `split.yml` guard step added: aborts before any split if unauthorized v1.0 tag
- [ ] Existing `v1.0*` tags detected and logged as `TAG_QUARANTINE_DETECTED`
- [ ] CI error message references `VERSIONING.md`

**Status:** Done. release-gate.yml created and split.yml updated.

**Implementation Tasks (remaining):**

- [ ] Create `release-approvals/` directory with README
- [ ] Document approval process in `VERSIONING.md` Section 6 (done)

---

## 3-Sprint Roadmap

### Sprint 0 — Week 1: Schemas, Manifests, Policy
**Owner:** Russell + platform team

| Task | Issue | Effort | Status |
|---|---|---|---|
| Write `VERSIONING.md` | ISSUE-15 | S | Done |
| Create `core.note` manifest + schema | ISSUE-01 | M | Done (manifests; impl pending) |
| Create issue templates (all 5) | ISSUE-15, ISSUE-16 | S | Done |
| Create release-gate.yml CI | ISSUE-16 | M | Done |
| Update split.yml | ISSUE-16 | S | Done |
| Draft onboarding UX copy | ISSUE-05 | S | Pending |
| Create GitHub labels + milestones | — | S | Pending |

### Sprint 1 — Weeks 2-3: Boot Validation, CI Gate, ACLs
**Owner:** Platform team

| Task | Issue | Effort |
|---|---|---|
| Boot validation (zero-type check) | ISSUE-02 | S |
| Default content type lifecycle | ISSUE-03 | S |
| Default ACLs and field access | ISSUE-04 | M |
| Namespace reservation | ISSUE-08 | S |
| Schema registry basics | ISSUE-10 | M |
| Boot validation PHPUnit CI step | ISSUE-09 | S |

### Sprint 2 — Weeks 4-5: Ingestion, Diagnostics, Migration, Docs
**Owner:** Platform team + docs

| Task | Issue | Effort |
|---|---|---|
| Ingestion envelope defaults | ISSUE-06 | M |
| Operator diagnostics | ISSUE-07 | S |
| Telemetry and audit defaults | ISSUE-11 | S |
| Security defaults | ISSUE-12 | M |
| Migration plan for existing tenants | ISSUE-14 | M |
| Onboarding flow (admin SPA) | ISSUE-05 | M |

### Sprint 3 — Week 6: Polish, Feature Flags, Release Milestone
**Owner:** Platform team

| Task | Issue | Effort |
|---|---|---|
| Feature flags and toggles | ISSUE-13 | S |
| Acceptance test matrix full run | — | S |
| v0.1-defaults milestone close | — | S |
| v0.1-release-approval checklist prep | — | S |

---

## Acceptance Test Matrix

| Test | Trigger | Expected |
|---|---|---|
| Boot with zero types | Platform start | `DEFAULT_TYPE_MISSING` error, boot halted |
| Boot with `core.note` only | Platform start | Success |
| Boot with `core.note` disabled | Platform start | `DEFAULT_TYPE_DISABLED` warning |
| Schema conformance: valid payload | POST /api/core.note | 201 Created |
| Schema conformance: missing title | POST /api/core.note | 422 Unprocessable |
| ACL: tenant.member reads note | GET /api/core.note/{id} | 200 OK |
| ACL: tenant.member writes note | POST /api/core.note | 403 Forbidden |
| ACL: anonymous reads note | GET /api/core.note/{id} | 401 Unauthorized |
| Delete core.note via API | DELETE /api/entity-type/core.note | 403 Forbidden |
| Disable core.note via API | PATCH /api/entity-type/core.note {status:disabled} | 200 + audit log |
| Manifest has project_versioning | CI on push | CI passes |
| Manifest missing project_versioning | CI on push | CI fails: MANIFEST_VERSIONING_MISSING |
| v1.0 tag without approval | git push v1.0 tag | CI fails: UNAUTHORIZED_V1_TAG |
| v1.0 tag with approval | git push v1.0 tag + approval file | CI passes |
| Ingest valid envelope | POST /api/ingest/core.note | 201 Created |
| Ingest missing provenance | POST /api/ingest/core.note | 422 + MISSING_PROVENANCE |
| core.note namespace guard | Register type core.custom via extension | 403: NAMESPACE_RESERVED |

---

## PR Checklist for Defaults and Versioning Changes

Copy this into every PR that touches `defaults/`, `VERSIONING.md`, or `.github/workflows/`:

```markdown
## Defaults & Versioning Checklist

- [ ] Does any changed manifest include or update `project_versioning`?
- [ ] Does this change include tests for boot validation and manifest conformance?
- [ ] Does this change avoid creating a `v1.0` tag without owner approval?
- [ ] Are migration steps and docs included for breaking schema changes?
- [ ] Has `VERSIONING.md` been updated if release policy changed?
- [ ] Has `VERSIONING.md` audit log been updated if a tag was deleted?
- [ ] Do all new `defaults/*.yaml` files have `project_versioning.release_stage: pre-v1`?
- [ ] Does CI pass locally (or via draft PR)?
```
