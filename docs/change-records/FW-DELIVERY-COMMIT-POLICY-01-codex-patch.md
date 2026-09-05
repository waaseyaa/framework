# Codex integration patch — FW-DELIVERY-COMMIT-POLICY-01 / #2903

Status: integrated by Codex in this review candidate. The proposal below is
retained as handoff history. Live policy is in the shared agent contract,
workflow spec, CLAUDE adapter, and CI README; no shared edits remain pending.
Cursor supplied the lane-owned material; Codex integrated the shared wording.

Lane-owned already on the candidate: `docs/cookbook/commit-qualification.md`,
`docs/change-records/FW-DELIVERY-COMMIT-POLICY-01.md`, Architecture
`CommitQualificationPolicyTest`, changelog fragment.

Do **not** add per-commit CI jobs or change `ci.yml` / `tools/preflight-gates.json`
for this slice.

---

## 1. `CLAUDE.md` — Commit & PR hygiene

**Delete** this bullet:

```markdown
- `composer test` must pass before any commit.
```

**Replace with:**

```markdown
- Intermediate branch commits may be recoverable checkpoints (including
  deliberately red TDD states). They are not release-ready. The one review
  candidate tip must be fully qualified before acceptance: `php bin/check-pr-preflight --full`
  plus the Unit, Integration, and Architecture suites locally, then CI green on
  the exact pushed head. Governed landings squash via `auto-merge-when-green`.
  See `docs/cookbook/commit-qualification.md`.
```

---

## 2. `docs/governance/agent-contract.md` — after “Keep one review candidate…”

**Insert:**

```markdown
- Intermediate unpublished or branch commits may be recoverable checkpoints;
  do not claim they are individually release-ready. Qualification binds the
  review-candidate head. Ordinary merges to `main` use the governed squash
  auto-merge path. The release-cut workflow is the documented non-squash
  exception (exact gated SHA); see `docs/cookbook/commit-qualification.md`.
```

---

## 3. `docs/specs/workflow.md` — after rule 3 (Review candidates must be traceable)

**Insert a short subsection:**

```markdown
### Commit checkpoints vs review candidates

Feature-branch commits may be recoverable checkpoints. Acceptance qualifies the
**review-candidate head** (exact-head CI), not every ancestor. Ordinary landings
use governed **squash** auto-merge. The release-cut path pushes an exact gated
SHA and must not be rewritten by squash/rebase/merge-commit — that is a distinct
supported boundary, not a general merge-commit policy. Detail:
[commit-qualification.md](../cookbook/commit-qualification.md). Do not add
per-commit full-suite CI jobs by default.
```

---

## 4. `docs/ci/README.md` — Git Hooks / pre-push (stale “advisory” text)

**Replace** the `### pre-push` subsection with:

```markdown
### pre-commit / pre-push

- **pre-commit:** portable-path check; `composer cs-check` when staged `.php` files exist.
- **pre-push:** `php bin/check-pr-preflight` — every fast repo-state gate CI blocks on
  (spec drift included; no advisory splits). Hosted CI on the exact PR head remains
  authoritative for acceptance. Run `php bin/check-pr-preflight --full` plus the
  three test suites before opening a review candidate. Intermediate commits are
  checkpoints; see `docs/cookbook/commit-qualification.md`.
```

---

## Landing ask

Land shared wording with other #2525/#2527 shared-file work. Lane PR stays
docs/tests/cookbook only until this patch is applied (or apply in the same
integration PR if coordinating a single shared-guidance commit).
