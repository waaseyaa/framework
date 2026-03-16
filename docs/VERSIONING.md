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
- Triggers on: `push` to tags matching `v1.0*`.
- If `release-approvals/v1.0.approved` does not exist: workflow fails with error `UNAUTHORIZED_V1_TAG` and posts a quarantine issue.
- If the file exists: workflow proceeds and logs the approval.

### split.yml (monorepo split)
- Added guard step: checks for `release-approvals/v1.0.approved` before executing the split-and-push.
- Any `v1.0*` tag without the approval file causes the split job to fail before touching any remote.

### release-gate.yml (boot validation CI)
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
