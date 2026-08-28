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

## 8. Forward-Only Releases & Single-Crawl Publish

Releases are **forward-only**. A tagged release version is **immutable** once
published to Packagist — Packagist keeps the dist for an existing tag and will
not adopt a moved tag (anti-tamper). Therefore:

- **Never re-tag.** If a cut release is bad, recover by cutting the **next**
  version (`alpha.N+1`), never by deleting/moving an existing tag. `release-cut.yml`
  already refuses to re-cut an existing version; `split.yml` pushes the release
  tag to each split repo **non-force** and fails with `RETAG_BLOCKED` if the tag
  already exists there at a different commit.

- **One crawl per package per release.** Packagist's GitHub auto-update **push
  webhooks are disabled** on the monorepo and every split repo. They fired once
  per pushed ref (gate-branch create + main fast-forward + tag + gate-branch
  delete on the monorepo; main + tag on each split repo), so GitHub delivered
  multiple webhooks and Packagist re-crawled/re-published the just-cut version
  2-3x (audit alpha.245 §1). Publishing is instead a single idempotent
  `POST /api/update-package` per package, run by the `publish-packagist` job in
  `split.yml` **after** split + tag-parity + require-parity succeed. That POST is
  the only crawl trigger, so each package is published exactly once. If
  `update-package` returns 404 (a brand-new split package's first release), the
  step falls back to exactly one `create-package` call to register the package;
  subsequent releases take the normal `update-package` path.

  Requires repo secrets `PACKAGIST_USERNAME` + `PACKAGIST_TOKEN` for the safe
  `update-package` operation and `PACKAGIST_MAIN_TOKEN` for Packagist's unsafe
  `create-package` operation. Creation uses Packagist's documented Bearer
  authentication form. The invariant is enforced in CI by
  `bin/check-release-publish-shape`.

**Cutover order (one-time, when this lands):** (1) merge this change; (2) set the
`PACKAGIST_USERNAME`/`PACKAGIST_TOKEN`/`PACKAGIST_MAIN_TOKEN` secrets; (3)
**then** delete the Packagist
auto-update webhook from the monorepo and every split repo. Do not delete the
webhooks before the POST mechanism + secrets are live, or a release would not
publish at all.

---

## 9. Development-only split main branches

Split repository `main` branches may be updated between releases solely for
audited downstream integration verification. This is not a release action and
does not authorize production promotion.

`.github/workflows/split-main.yml` is the only supported pre-release path. It:

- runs only through `workflow_dispatch` by a collaborator with write-or-higher permission;
- accepts only package names compiled into `bin/resolve-split-main-targets`' reviewed allowlist;
- accepts only the full SHA currently at framework `main` (older, unmerged, abbreviated, or otherwise stale SHAs fail);
- requires the authoritative CI workflow to be green for that exact SHA;
- refuses to overlap a queued or running tagged split/fan-out;
- pushes only the selected package's `refs/heads/main`, guarded by `--force-with-lease`;
- verifies the remote result, overlays the canonical contribution-routing files,
  and uploads monorepo-SHA, split-SHA, and final mirror-main provenance;
- never creates or moves tags, mutates `VERSION`/changelog content, calls Packagist, or publishes a GitHub Release.

The overlay commit is deterministic: its parent, tree, message, bot identity,
and dates all derive from the exact split commit and tracked templates. Re-running
the same source SHA therefore preserves the same mirror-main SHA. A lease failure means another
split or release changed the remote and must be investigated; do not bypass it.
Tagged `split.yml` remains the sole release and package-publication authority.
Both split paths add the same one-commit community-health overlay to each
generated `main` branch. The overlay disables blank issues, redirects issue
authors to the Framework monorepo, and warns pull-request authors that mirror
changes are overwritten. Release tags remain on the byte-exact subtree split
commit and never include this operational overlay. Tagged splitting pushes the
raw tag and routed `main` atomically, so a tag conflict or transport failure
cannot strip the contribution boundary from the mirror.

---

## Audit Log

_No entries yet. Records of tag deletions and v1.0 authorizations will appear here._

---

## Version History of This Document

| Date | Change | Author |
|---|---|---|
| 2026-03-07 | Initial versioning policy created | @jonesrussell |
