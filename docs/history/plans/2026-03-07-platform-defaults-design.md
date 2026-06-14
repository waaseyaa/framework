# Platform Defaults & Versioning Policy — Design

**Date:** 2026-03-07
**Status:** Approved
**Branch:** main

## Problem

Waaseyaa has no codified platform defaults, no built-in content type that guarantees a valid boot state, and no versioning policy that prevents accidental promotion to v1.0. The existing `split.yml` CI workflow triggers on any `v*` tag with no approval gate.

## Goals

1. Ship a minimal built-in content type (`core.note`) that guarantees a valid platform state at boot.
2. Codify a Pre-v1 Continuation Rule: the project stays at `0.x` until Russell explicitly authorizes `v1.0`.
3. Enforce the versioning policy in CI (block unauthorized `v1.0` tags).
4. Provide GitHub issue templates, labels, milestones, and a 3-sprint roadmap.
5. Produce a `release_quarantine` workflow for any preexisting `v1.0` tags.

## Scope of Artifacts

### Files to create in repo

| Path | Purpose |
|---|---|
| `VERSIONING.md` | Authoritative versioning policy |
| `defaults/README.md` | Explains the defaults directory |
| `defaults/core.note.yaml` | Built-in content type manifest (incl. `project_versioning`) |
| `defaults/core.note.schema.json` | JSON Schema for `core.note` |
| `.github/ISSUE_TEMPLATE/default-type.md` | Issue template: default content type changes |
| `.github/ISSUE_TEMPLATE/rfc.md` | Issue template: RFCs |
| `.github/ISSUE_TEMPLATE/migration.md` | Issue template: migrations |
| `.github/ISSUE_TEMPLATE/release-approval.md` | Issue template: signed release PR |
| `.github/ISSUE_TEMPLATE/release-quarantine.md` | Issue template: quarantine preexisting v1.0 tags |
| `.github/workflows/release-gate.yml` | CI: blocks v1.0 tag without approval artifact |
| `docs/history/plans/2026-03-07-github-issues-sprint.md` | All 16 GitHub issues, copy-pasteable |

### Files to modify

| Path | Change |
|---|---|
| `.github/workflows/split.yml` | Add version gate step before split/push |

## Architecture Decisions

### core.note
- Stored under `defaults/` in the monorepo root (not inside any package) — it is a platform-level artifact, not a package concern.
- Manifest is YAML; schema is JSON Schema draft-07.
- `project_versioning` block is mandatory in all default manifests.
- The type is immutable via API (no DELETE), but disableable per tenant with audit log.

### Versioning policy
- Single authoritative file: `VERSIONING.md`.
- CI reads a sentinel file `release-approvals/v1.0.approved` (created by a PR that Russell merges) to gate the split workflow.
- If the sentinel is absent and the tag is `v1.0`, CI fails with `UNAUTHORIZED_V1_TAG`.

### Issue templates
- GitHub markdown-format templates under `.github/ISSUE_TEMPLATE/`.
- Each includes versioning fields in the body.

### Sprint roadmap
- Sprint 0 (week 1): manifests, VERSIONING.md, issue skeletons
- Sprint 1 (weeks 2-3): API guardrails, boot validation CI, ACL defaults, CI gate
- Sprint 2 (weeks 4-5): ingestion defaults, diagnostics, migration plan, docs
- Sprint 3 (week 6): polish, tests, v0.1-defaults milestone release

## Non-Goals

- Implementing the `core.note` API controller (that is a separate issue, tracked in the sprint).
- Migrating existing tenants automatically (tracked as a migration issue).
- Executing the v1.0 release process (kept in repo as a checklist, not executed).
