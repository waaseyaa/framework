# Platform Defaults & Versioning Policy Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Create all repo artifacts for the Waaseyaa platform defaults planning sprint: versioning policy, built-in content type manifest, CI gate, GitHub issue templates, and the full issues sprint document.

**Architecture:** All artifacts live at the repo root level or under `.github/`. The `defaults/` directory holds platform-level manifests (not package concerns). CI enforcement is added to an existing `split.yml` workflow and a new `release-gate.yml`. The issues sprint doc consolidates all 16 GitHub issues as copy-pasteable markdown.

**Tech Stack:** YAML manifests, JSON Schema draft-07, GitHub Actions, Markdown

---

### Task 1: Create `VERSIONING.md`

**Files:**
- Create: `VERSIONING.md`

**Step 1: Write the file**

Create `/home/jones/dev/waaseyaa/VERSIONING.md` with this exact content:

```markdown
# Waaseyaa Versioning Policy

This file is **authoritative** for all release and versioning decisions. It supersedes any other documentation on this topic.

---

## 1. Pre-v1 Continuation Rule

The project remains in **pre-v1** (semantic major version `0.x`) until **Russell** (GitHub: `@jonesrussell`) authorizes a formal `v1.0` release.

- No automated process, team vote, or CI pipeline may promote the project to `v1.0` without explicit owner sign-off.
- Owner sign-off is defined as: Russell merging a PR that creates the file `release-approvals/v1.0.approved` in the repository root.
- Until that file exists, all `v1.0` tag creation attempts are blocked by CI.

---

## 2. Tagged v1.0 Handling (Quarantine Process)

If a `v1.0` tag is discovered on any branch or in any package repo:

1. **Do not delete it immediately.**
2. Open a GitHub issue using the `release-quarantine` template (`.github/ISSUE_TEMPLATE/release-quarantine.md`).
3. The issue must document: who created the tag, when, on which commit, and why.
4. Russell reviews and confirms in writing (GitHub comment or PR approval) whether to keep or delete.
5. If deletion is approved, delete the tag and record the action in this file under the **Audit Log** section.
6. CI detects existing `v1.0` tags and opens a quarantine issue automatically via the `release-gate.yml` workflow.

---

## 3. Versioning Manifest Block

All default manifests, schemas, and built-in types must include a `project_versioning` block:

```yaml
project_versioning:
  release_stage: pre-v1          # pre-v1 | v1.0 | v1.x
  owner: jonesrussell             # GitHub handle of release authority
  release_approval_required: true # must be true until v1.0 is authorized
  tag_policy: deletable-with-owner-approval  # immutable | deletable-with-owner-approval
```

CI validates that every file under `defaults/` contains this block.

---

## 4. Compatibility and Schema Rules

### Pre-v1 (current)
- Schema changes **may be breaking** by default.
- Breaking changes must be documented in the relevant migration issue and gated by migration tasks.
- No backwards-compatibility guarantee between `0.x` releases.

### Post-v1.0 (future, requires owner authorization)
- Semantic versioning applies strictly.
- Breaking changes require a major version bump.
- Each breaking change requires a documented migration path.

---

## 5. CI Enforcement

### release-gate.yml
- Triggers on: `push` to tags matching `v1.0*` or `v1.0`.
- If `release-approvals/v1.0.approved` does not exist: workflow fails with error `UNAUTHORIZED_V1_TAG` and posts a quarantine issue.
- If the file exists: workflow proceeds and logs the approval.

### split.yml (monorepo split)
- Added guard step: checks for `release-approvals/v1.0.approved` before executing the split-and-push.
- Any `v1.0*` tag without the approval file causes the split job to fail before touching any remote.

### manifest-versioning-check.yml (boot validation CI)
- Validates every file under `defaults/` contains a well-formed `project_versioning` block.
- Runs on every push to `main` and on every PR.

---

## 6. Approval Workflow (When Russell Authorizes v1.0)

1. Russell opens a PR that creates `release-approvals/v1.0.approved` with content:
   ```
   Authorized by: @jonesrussell
   Date: YYYY-MM-DD
   Commit: <sha>
   Notes: <reason>
   ```
2. The PR must have Russell as both author and approver (self-approval for owner authorization).
3. CI on the PR verifies the file format.
4. On merge, subsequent `v1.0` tags are unblocked.
5. Update `VERSIONING.md` Audit Log with the authorization record.

---

## 7. Operator Diagnostics for Versioning Errors

| Code | Trigger | Message | Remediation |
|---|---|---|---|
| `UNAUTHORIZED_V1_TAG` | CI detects v1.0 tag without approval file | "v1.0 tag created without owner approval. Pipeline aborted." | Open `release-quarantine` issue, notify @jonesrussell |
| `TAG_QUARANTINE_DETECTED` | Existing v1.0 tag found | "Existing v1.0 tag detected. Tag: <name>, Creator: <user>, Commit: <sha>" | Follow quarantine process in Section 2 |
| `MANIFEST_VERSIONING_MISSING` | Default manifest lacks `project_versioning` block | "Manifest <file> is missing required project_versioning block." | Add the block per Section 3 template |

---

## Audit Log

_No entries yet. Records of tag deletions and v1.0 authorizations will appear here._

---

## Version History of This Document

| Date | Change | Author |
|---|---|---|
| 2026-03-07 | Initial versioning policy created | @jonesrussell |
```

**Step 2: Verify the file exists**

Run: `ls -la VERSIONING.md`
Expected: file listed, non-zero size

**Step 3: Commit**

```bash
git add VERSIONING.md
git commit -m "docs: add VERSIONING.md with pre-v1 continuation rule and quarantine workflow"
```

---

### Task 2: Create `defaults/` directory, README, and `core.note` artifacts

**Files:**
- Create: `defaults/README.md`
- Create: `defaults/core.note.yaml`
- Create: `defaults/core.note.schema.json`

**Step 1: Create `defaults/README.md`**

```markdown
# Waaseyaa Platform Defaults

This directory contains built-in platform-level manifests for Waaseyaa's default content types,
schemas, and configuration.

## Rules

- Files in this directory are **immutable via API** — they cannot be deleted through the platform API.
- They may be **disabled per tenant** via admin UI or CLI, with an audit log entry.
- Every manifest must include a `project_versioning` block (see `VERSIONING.md`).
- Do not place package-specific defaults here; this is for platform-wide built-ins only.

## Contents

| File | Description |
|---|---|
| `core.note.yaml` | Built-in minimal content type: a simple note with title and body |
| `core.note.schema.json` | JSON Schema (draft-07) for the `core.note` entity payload |

## Namespace

Built-in types use the `core.` namespace prefix. Custom types must use a different namespace.
The `core.` namespace is reserved and cannot be claimed by extensions or tenants.

## Adding a New Default

1. Create `<namespace>.<type>.yaml` with `project_versioning` block.
2. Create `<namespace>.<type>.schema.json` (JSON Schema draft-07).
3. Add the API guard in the entity storage layer (prevent DELETE, allow disable).
4. Add boot validation test.
5. Add CI manifest conformance check.
6. Open a GitHub issue using the `default-type` template.
```

**Step 2: Create `defaults/core.note.yaml`**

```yaml
# core.note — Waaseyaa built-in content type
# This manifest is immutable. Do not delete. Disable per tenant via admin UI or CLI.

id: core.note
label: Note
namespace: core
version: "0.1.0"
status: active

project_versioning:
  release_stage: pre-v1
  owner: jonesrussell
  release_approval_required: true
  tag_policy: deletable-with-owner-approval

schema:
  ref: core.note.schema.json
  version: "0.1.0"
  compatibility: liberal  # pre-v1: breaking changes allowed with migration task

entity_keys:
  id: id
  uuid: uuid
  label: title
  owner: tenant_id

fields:
  id:
    type: integer
    label: ID
    system: true
    writable: false
  uuid:
    type: uuid
    label: UUID
    system: true
    writable: false
  tenant_id:
    type: string
    label: Tenant ID
    system: true
    writable: false
  title:
    type: string
    label: Title
    required: true
    max_length: 512
  body:
    type: text
    label: Body
    required: false
  created_at:
    type: timestamp
    label: Created At
    system: true
    writable: false
  updated_at:
    type: timestamp
    label: Updated At
    system: true
    writable: false

access_control:
  read:
    - tenant.member
    - tenant.admin
    - platform.admin
  write:
    - tenant.admin
    - platform.admin
  delete: []           # deletion blocked — disable only
  field_overrides:
    id:
      read: [tenant.member, tenant.admin, platform.admin]
      write: []
    uuid:
      read: [tenant.member, tenant.admin, platform.admin]
      write: []
    tenant_id:
      read: [tenant.admin, platform.admin]
      write: []

lifecycle:
  deletable: false
  disableable: true
  disable_requires_audit_log: true
  disable_requires_replacement: false  # platform can operate with zero active content types post-disable

onboarding:
  show_in_type_picker: true
  onboarding_label: "Note (built-in)"
  description: "A simple built-in note type. Use this to get started or as a reference for custom types."
  quickstart_example: core.note.example.json

ingestion:
  envelope_version: "1"
  required_provenance_fields:
    - source
    - ingested_at
  validation: strict    # reject invalid payloads; do not silently drop fields
  error_handling: log-and-reject

diagnostics:
  boot_required: true   # platform boot fails if this type is missing AND no other type exists
  error_codes:
    missing: DEFAULT_TYPE_MISSING
    disabled: DEFAULT_TYPE_DISABLED

telemetry:
  audit_fields:
    - actor
    - action
    - entity_id
    - tenant_id
    - timestamp
  retention_policy: 90d
  replay_metadata: true

namespace_rules:
  namespace: core
  tenant_override: false   # tenants cannot override core namespace types
  extension_override: false
```

**Step 3: Create `defaults/core.note.schema.json`**

```json
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "$id": "waaseyaa://defaults/core.note",
  "title": "core.note",
  "description": "Waaseyaa built-in Note content type. Minimal, generic, stable.",
  "type": "object",
  "required": ["title", "tenant_id"],
  "additionalProperties": false,
  "properties": {
    "id": {
      "type": "integer",
      "description": "Auto-assigned primary key. Read-only.",
      "readOnly": true
    },
    "uuid": {
      "type": "string",
      "format": "uuid",
      "description": "Stable public identifier. Read-only.",
      "readOnly": true
    },
    "tenant_id": {
      "type": "string",
      "description": "Owning tenant identifier.",
      "minLength": 1
    },
    "title": {
      "type": "string",
      "description": "Note title.",
      "minLength": 1,
      "maxLength": 512
    },
    "body": {
      "type": "string",
      "description": "Note body. Plain text or Markdown.",
      "default": ""
    },
    "created_at": {
      "type": "string",
      "format": "date-time",
      "description": "Creation timestamp (ISO 8601). Read-only.",
      "readOnly": true
    },
    "updated_at": {
      "type": "string",
      "format": "date-time",
      "description": "Last update timestamp (ISO 8601). Read-only.",
      "readOnly": true
    }
  },
  "x-waaseyaa": {
    "entity_type": "core.note",
    "namespace": "core",
    "version": "0.1.0",
    "project_versioning": {
      "release_stage": "pre-v1",
      "owner": "jonesrussell",
      "release_approval_required": true,
      "tag_policy": "deletable-with-owner-approval"
    },
    "immutable": true,
    "disableable": true,
    "boot_required": true
  }
}
```

**Step 4: Verify files**

Run: `ls -la defaults/`
Expected: README.md, core.note.yaml, core.note.schema.json

**Step 5: Commit**

```bash
git add defaults/
git commit -m "feat: add defaults/ directory with core.note manifest, schema, and README"
```

---

### Task 3: Create GitHub Issue Templates

**Files:**
- Create: `.github/ISSUE_TEMPLATE/default-type.md`
- Create: `.github/ISSUE_TEMPLATE/rfc.md`
- Create: `.github/ISSUE_TEMPLATE/migration.md`
- Create: `.github/ISSUE_TEMPLATE/release-approval.md`
- Create: `.github/ISSUE_TEMPLATE/release-quarantine.md`

**Step 1: Create `.github/ISSUE_TEMPLATE/default-type.md`**

```markdown
---
name: Default Content Type Change
about: Propose a change to a built-in platform default (core.* types)
labels: defaults, schema, infra
---

## Summary

<!-- What is changing and why? -->

## Acceptance Criteria

- [ ] Schema change is documented and backwards-compatible (or migration task exists)
- [ ] Manifest includes `project_versioning` block
- [ ] Boot validation test updated
- [ ] API guard tested (DELETE blocked, disable with audit log works)
- [ ] CI manifest conformance check passes

## Implementation Tasks

- [ ] Update manifest under `defaults/`
- [ ] Update JSON Schema
- [ ] Update API guard if needed
- [ ] Update migration plan if breaking
- [ ] Update `VERSIONING.md` if policy changes
- [ ] Add/update tests
- [ ] Update docs

## Estimated Effort

<!-- S / M / L -->

## Priority

<!-- P0 / P1 / P2 -->

## Labels

`defaults` `schema` `infra` `versioning` `p0/p1/p2`

## Milestone

<!-- v0.1-defaults / v0.2-onboarding / v0.3-migrations -->

## Dependencies

<!-- List issue numbers -->

## Notes

<!-- UX copy, API contract snippets, sample JSON/YAML -->

## Versioning Checklist

- [ ] Does this change affect `project_versioning` in any manifest?
- [ ] Does this avoid creating a `v1.0` tag without owner approval?
- [ ] Has `VERSIONING.md` been updated if release policy changed?
```

**Step 2: Create `.github/ISSUE_TEMPLATE/rfc.md`**

```markdown
---
name: RFC — Request for Comments
about: Propose a significant architectural or policy change
labels: rfc, docs
---

## Motivation

<!-- Why is this change needed? What problem does it solve? -->

## Proposal

<!-- Describe the change in detail -->

## Alternatives Considered

<!-- What other approaches did you consider? -->

## Impact Assessment

- [ ] Does this affect platform defaults?
- [ ] Does this affect the versioning policy (`VERSIONING.md`)?
- [ ] Does this require a migration plan?
- [ ] Does this change any API contracts?
- [ ] Does this affect CI enforcement?

## Acceptance Criteria

<!-- Clear, testable criteria -->

## Estimated Effort

<!-- S / M / L -->

## Priority

<!-- P0 / P1 / P2 -->

## Labels

`rfc` `docs` `p0/p1/p2`

## Milestone

<!-- milestone -->

## Notes

<!-- References, prior art, links -->
```

**Step 3: Create `.github/ISSUE_TEMPLATE/migration.md`**

```markdown
---
name: Migration Plan
about: Track a breaking change and its migration path for existing tenants
labels: migration
---

## Breaking Change Description

<!-- What changed and why it breaks existing deployments -->

## Affected Tenants / Configurations

<!-- Who is affected? How to detect? -->

## Migration Steps

1. <!-- Step 1 -->
2. <!-- Step 2 -->
3. <!-- Step 3 -->

## Rollback Steps

1. <!-- Rollback step 1 -->
2. <!-- Rollback step 2 -->

## Safe Toggle

<!-- Is there a feature flag or per-tenant toggle to disable/enable the new behavior? -->

## Automated Smoke Tests

- [ ] Migration smoke test added to CI
- [ ] Rollback smoke test added to CI

## Versioning Impact

- [ ] Is this change breaking under pre-v1 rules? (It may be; document it.)
- [ ] Would this require a major version bump post-v1.0?
- [ ] `VERSIONING.md` updated if compatibility rules changed?

## Acceptance Criteria

<!-- Clear, testable criteria for migration completion -->

## Estimated Effort

<!-- S / M / L -->

## Priority

<!-- P0 / P1 / P2 -->

## Labels

`migration` `p0/p1/p2`

## Milestone

<!-- milestone -->

## Dependencies

<!-- List issue numbers -->
```

**Step 4: Create `.github/ISSUE_TEMPLATE/release-approval.md`**

```markdown
---
name: Release Approval (v1.0)
about: Formal owner sign-off to authorize a v1.0 release. DO NOT OPEN without Russell's explicit instruction.
labels: versioning, release-approval
---

> **WARNING:** This template is for the formal v1.0 release authorization process only.
> Do not open this issue speculatively. Russell must initiate this process explicitly.

## Release Version

`v1.0.0`

## Owner Authorization

- Authorized by: @jonesrussell
- Authorization date: <!-- YYYY-MM-DD -->
- Authorization method: This issue + PR merging `release-approvals/v1.0.approved`

## Pre-Release Checklist

- [ ] All P0 issues in milestone `v0.1-defaults` are closed
- [ ] All P0 issues in milestone `v0.2-onboarding` are closed
- [ ] Boot validation CI passes on main
- [ ] Schema conformance CI passes on main
- [ ] ACL enforcement tests pass
- [ ] Migration smoke tests pass
- [ ] `VERSIONING.md` updated with authorization record
- [ ] `release-approvals/v1.0.approved` file created in this PR
- [ ] Monorepo split CI (`split.yml`) will be unblocked after merge

## Approval Artifact PR

<!-- Link to the PR that creates release-approvals/v1.0.approved -->

## Notes

<!-- Any release notes, known issues, or post-release tasks -->
```

**Step 5: Create `.github/ISSUE_TEMPLATE/release-quarantine.md`**

```markdown
---
name: Release Quarantine — Unauthorized v1.0 Tag
about: Track and resolve an unauthorized v1.0 tag. Auto-opened by CI when UNAUTHORIZED_V1_TAG is detected.
labels: versioning, release-quarantine, p0
---

> **This issue was opened automatically by CI or manually by a team member.**
> An unauthorized `v1.0` tag has been detected. See `VERSIONING.md` for the quarantine process.

## Tag Details

- **Tag name:** <!-- e.g. v1.0.0 -->
- **Created by:** <!-- GitHub username -->
- **Created at:** <!-- datetime -->
- **On commit:** <!-- SHA -->
- **Repository:** <!-- monorepo or split package repo -->

## Quarantine Checklist

- [ ] Tag details documented above
- [ ] @jonesrussell notified (comment tagging him below)
- [ ] Russell reviewed and confirmed disposition in writing (comment or PR approval)

## Russell's Decision

<!-- To be filled in by Russell -->

- [ ] **Keep tag** — tag was authorized; proceed with release approval workflow
- [ ] **Delete tag** — tag was created in error

## Deletion Record (if applicable)

- Deleted by: <!-- username -->
- Deleted at: <!-- datetime -->
- Deletion authorized in: <!-- link to Russell's comment/PR -->
- `VERSIONING.md` audit log updated: <!-- yes/no -->

## Diagnostic Info

```
Error code: TAG_QUARANTINE_DETECTED
Tag: <tag-name>
Creator: <username>
Commit: <sha>
Required action: Owner review per VERSIONING.md Section 2
```

## References

- [VERSIONING.md — Section 2: Tagged v1.0 Handling](../../VERSIONING.md)
- [Release Approval template](.github/ISSUE_TEMPLATE/release-approval.md)
```

**Step 6: Verify templates**

Run: `ls -la .github/ISSUE_TEMPLATE/`
Expected: 5 .md files listed

**Step 7: Commit**

```bash
git add .github/ISSUE_TEMPLATE/
git commit -m "feat: add GitHub issue templates for defaults, RFC, migration, release-approval, and release-quarantine"
```

---

### Task 4: Create `release-gate.yml` CI Workflow

**Files:**
- Create: `.github/workflows/release-gate.yml`

**Step 1: Write the workflow**

```yaml
name: Release Gate

on:
  push:
    tags:
      - 'v1.0*'
  workflow_dispatch:
    inputs:
      tag:
        description: Tag to validate
        required: true

jobs:
  check-approval:
    name: Verify v1.0 release authorization
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with:
          fetch-depth: 0

      - name: Check for v1.0 approval artifact
        id: approval
        run: |
          if [ ! -f "release-approvals/v1.0.approved" ]; then
            echo "::error::UNAUTHORIZED_V1_TAG: v1.0 tag created without owner approval."
            echo "::error::See VERSIONING.md Section 1 and open a release-quarantine issue."
            echo "::error::Tag: ${{ github.ref_name }}"
            echo "::error::Commit: ${{ github.sha }}"
            exit 1
          fi
          echo "Approval artifact found:"
          cat release-approvals/v1.0.approved

      - name: Validate approval artifact format
        if: steps.approval.outcome == 'success'
        run: |
          FILE="release-approvals/v1.0.approved"
          if ! grep -q "Authorized by:" "$FILE"; then
            echo "::error::Approval artifact missing 'Authorized by:' field. See VERSIONING.md Section 6."
            exit 1
          fi
          if ! grep -q "Date:" "$FILE"; then
            echo "::error::Approval artifact missing 'Date:' field."
            exit 1
          fi
          echo "Approval artifact format valid."

  check-manifests:
    name: Validate default manifests contain project_versioning
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Check project_versioning block in all defaults
        run: |
          FAILED=0
          for f in defaults/*.yaml; do
            if ! grep -q "project_versioning:" "$f"; then
              echo "::error::MANIFEST_VERSIONING_MISSING: $f lacks project_versioning block"
              FAILED=1
            fi
          done
          if [ "$FAILED" -eq 1 ]; then
            exit 1
          fi
          echo "All manifests contain project_versioning block."

  detect-quarantine:
    name: Detect existing v1.0 tags (quarantine scan)
    runs-on: ubuntu-latest
    if: github.event_name == 'push'
    steps:
      - uses: actions/checkout@v4
        with:
          fetch-depth: 0

      - name: Scan for unauthorized v1.0 tags
        run: |
          APPROVAL="release-approvals/v1.0.approved"
          V1_TAGS=$(git tag --list 'v1.0*')
          if [ -n "$V1_TAGS" ] && [ ! -f "$APPROVAL" ]; then
            echo "::warning::TAG_QUARANTINE_DETECTED: Unauthorized v1.0 tag(s) found: $V1_TAGS"
            echo "Open a release-quarantine issue per VERSIONING.md Section 2."
          fi
```

**Step 2: Verify file exists**

Run: `ls -la .github/workflows/release-gate.yml`

**Step 3: Commit**

```bash
git add .github/workflows/release-gate.yml
git commit -m "ci: add release-gate.yml to block unauthorized v1.0 tag creation"
```

---

### Task 5: Update `split.yml` with Version Gate

**Files:**
- Modify: `.github/workflows/split.yml`

**Step 1: Add approval guard step before the split-and-push step**

Read the current file first, then add the following step between the `Install splitsh-lite` step and the `Split and push` step:

```yaml
      - name: Guard against unauthorized v1.0 tag
        if: startsWith(github.ref_name, 'v1.0')
        run: |
          if [ ! -f "release-approvals/v1.0.approved" ]; then
            echo "::error::UNAUTHORIZED_V1_TAG: Monorepo split aborted."
            echo "::error::A v1.0 tag was pushed without owner approval. See VERSIONING.md."
            exit 1
          fi
          echo "v1.0 approval artifact verified. Proceeding with split."
```

**Step 2: Verify the updated file contains the guard**

Run: `grep -n "UNAUTHORIZED_V1_TAG" .github/workflows/split.yml`
Expected: line number with the error message

**Step 3: Commit**

```bash
git add .github/workflows/split.yml
git commit -m "ci: guard split.yml against unauthorized v1.0 tag propagation"
```

---

### Task 6: Create `docs/history/plans/2026-03-07-github-issues-sprint.md`

**Files:**
- Create: `docs/history/plans/2026-03-07-github-issues-sprint.md`

**Step 1: Write the issues sprint document**

This is the largest file. Write it with all 16 GitHub issues in full, including the example bodies specified in the prompt. Content follows below — write this exactly as given.

The file content is the full issues sprint document defined in the next section of this plan (see Issues Sprint Document, below). It is separated for readability.

**Step 2: Verify file exists**

Run: `ls -la docs/history/plans/2026-03-07-github-issues-sprint.md`

**Step 3: Commit**

```bash
git add docs/history/plans/2026-03-07-github-issues-sprint.md
git commit -m "docs: add platform defaults GitHub issues sprint (16 issues)"
```

---

### Task 7: Verify Everything and Final Commit

**Step 1: Run full tree check**

Run: `find VERSIONING.md defaults/ .github/ISSUE_TEMPLATE/ .github/workflows/release-gate.yml docs/history/plans/2026-03-07-* -type f | sort`

Expected output (all 12 files):
```
VERSIONING.md
defaults/README.md
defaults/core.note.schema.json
defaults/core.note.yaml
.github/ISSUE_TEMPLATE/default-type.md
.github/ISSUE_TEMPLATE/migration.md
.github/ISSUE_TEMPLATE/release-approval.md
.github/ISSUE_TEMPLATE/release-quarantine.md
.github/ISSUE_TEMPLATE/rfc.md
.github/workflows/release-gate.yml
docs/history/plans/2026-03-07-github-issues-sprint.md
docs/history/plans/2026-03-07-platform-defaults-design.md
```

**Step 2: Verify split.yml has the guard**

Run: `grep -c "UNAUTHORIZED_V1_TAG" .github/workflows/split.yml`
Expected: `1`

**Step 3: Verify all defaults manifests have project_versioning**

Run: `grep -l "project_versioning" defaults/*.yaml`
Expected: `defaults/core.note.yaml`

**Step 4: Check git log**

Run: `git log --oneline -8`
Expected: all task commits visible

---

## Issues Sprint Document Content

The following is the full content of `docs/history/plans/2026-03-07-github-issues-sprint.md`.
Write it verbatim as Task 6 Step 1.

---

```markdown
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
- [ ] If zero types: emits `DEFAULT_TYPE_MISSING` error, logs remediation steps, halts boot
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
  Docs: https://docs.waaseyaa.dev/defaults

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
- [ ] Add CLI command `waaseyaa type:enable <id>` and `waaseyaa type:disable <id>`
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
- [ ] Unit tests: one per role × operation combination (at minimum 8 tests)
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

Define the canonical ingestion envelope format for `core.note` and implement validation. Payloads missing required provenance fields must be rejected, not silently dropped.

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

- [ ] Create `.github/workflows/release-gate.yml`
- [ ] Add manifest conformance check step
- [ ] Add JSON Schema validation step (use `ajv-cli` or equivalent)
- [ ] Add boot validation PHPUnit test run step
- [ ] Add unauthorized tag detection step
- [ ] Update `split.yml` with version gate guard

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
- [ ] Add `compatibility` field to manifest format
- [ ] Add CLI command `waaseyaa schema:list`
- [ ] Unit tests: registry loads `core.note` schema correctly
- [ ] Document compatibility rules in `VERSIONING.md` Section 4

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
- [ ] Security defaults documented in `docs/specs/` security spec

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
- [ ] Versioning note: pre-v1, migration is best-effort; post-v1 migration is required with docs

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

Create `VERSIONING.md` as the authoritative versioning policy. Define the Pre-v1 Continuation Rule, the quarantine process for unauthorized `v1.0` tags, and the owner approval workflow. Add the `release-quarantine` issue template.

**Acceptance Criteria:**

- [ ] `VERSIONING.md` exists at repo root with all 7 sections
- [ ] `release-quarantine` issue template exists at `.github/ISSUE_TEMPLATE/release-quarantine.md`
- [ ] `release-approval` issue template exists
- [ ] All three versioning rules codified verbatim
- [ ] Audit log section present (initially empty)
- [ ] CI references `VERSIONING.md` in error messages

**Implementation Tasks:**

- [ ] Write `VERSIONING.md`
- [ ] Write `release-quarantine.md` issue template
- [ ] Write `release-approval.md` issue template
- [ ] Reference `VERSIONING.md` in `release-gate.yml` error output
- [ ] Add link to `VERSIONING.md` from `README.md` (if exists)

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

**Implementation Tasks:**

- [ ] Create `.github/workflows/release-gate.yml`
- [ ] Add guard step to `.github/workflows/split.yml`
- [ ] Add `release-approvals/` directory with `.gitkeep` and `README.md`
- [ ] Unit/integration test: simulate tag push, verify CI fails
- [ ] Document approval process in `VERSIONING.md` Section 6

---

## 3-Sprint Roadmap

### Sprint 0 — Week 1: Schemas, Manifests, Policy
**Owner:** Russell + platform team

| Task | Issue | Effort |
|---|---|---|
| Write `VERSIONING.md` | ISSUE-15 | S |
| Create `core.note` manifest + schema | ISSUE-01 | M |
| Create issue templates (all 5) | ISSUE-15, ISSUE-16 | S |
| Draft onboarding UX copy | ISSUE-05 | S |
| Create GitHub labels + milestones | — | S |

### Sprint 1 — Weeks 2-3: Boot Validation, CI Gate, ACLs
**Owner:** Platform team

| Task | Issue | Effort |
|---|---|---|
| Boot validation (zero-type check) | ISSUE-02 | S |
| Default content type lifecycle | ISSUE-03 | S |
| Default ACLs and field access | ISSUE-04 | M |
| Namespace reservation | ISSUE-08 | S |
| Schema registry basics | ISSUE-10 | M |
| Release gate CI workflow | ISSUE-16 | M |
| Boot validation CI job | ISSUE-09 | M |

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
```

---

## Summary

Tasks in execution order:

1. Task 1 — `VERSIONING.md`
2. Task 2 — `defaults/` directory (README + core.note manifest + schema)
3. Task 3 — `.github/ISSUE_TEMPLATE/` (5 templates)
4. Task 4 — `.github/workflows/release-gate.yml`
5. Task 5 — Update `.github/workflows/split.yml`
6. Task 6 — `docs/history/plans/2026-03-07-github-issues-sprint.md`
7. Task 7 — Verification
