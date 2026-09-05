# FW-DELIVERY-COMMIT-POLICY-01 — align green-commit guarantees with squash landings

- Status: review
- Parent programme: Framework #2527
- Forge mirror: Framework #2903
- Worktree lease: `8f64459cabcc1d72a9328eb158d8e43b` at
  `/home/fsd42/dev/waaseyaa-worktrees/fw-2903-commit-policy`
- Design-start head: `5bac44286a77ec99abb2a2b53a9cf823298c94a7`

## Intent

Agents have been treating every branch commit as if it must be individually
release-ready (`composer test` before every commit), while the governed path
**squashes** PRs and qualifies the **exact review-candidate head**. This change
reconciles written guidance with what hooks and CI actually enforce, without
weakening pre-acceptance verification or adding per-commit full-suite jobs.

## Inventory — what is actually enforced today

| Surface | What it enforces | Individually green commits? |
| --- | --- | --- |
| `bin/project-hooks` `pre-commit` | `bin/check-portable-paths`; if staged `.php`, `composer cs-check` | No |
| `bin/project-hooks` `pre-push` | `php bin/check-pr-preflight` (fast repo-state gates only) | No — not full suites |
| Hosted CI on PR head | Required ruleset checks on the **exact pushed SHA** | Qualifies the **candidate**, not every ancestor |
| `.github/workflows/auto-merge.yml` | Native `gh pr merge --auto --squash` when `auto-merge-when-green` | Squash collapses history on `main` |
| `docs/governance/agent-contract.md` | Design-first/TDD; one review candidate; exact-head evidence; governed merge | Does **not** say every commit must pass `composer test` |
| `AGENTS.md` | Points at the contract; no per-commit suite rule | No |
| `docs/specs/workflow.md` | Traceable review candidates; governed gates pointer | No per-commit suite rule |
| `CLAUDE.md` “Commit & PR hygiene” | **Misleading:** `` `composer test` must pass before any commit. `` | Written as if yes; **not** backed by hooks/CI |

### Adversarial finding (failing guidance vs enforcement)

At design-start head `5bac44286`:

1. `CLAUDE.md` line ~211 asserts every commit must pass `composer test`.
2. `bin/project-hooks` never invokes `phpunit` or `composer test` (Architecture
   `ProjectHooksTest` already pins this).
3. Publication remains: pre-push fast preflight + `--full` + three suites before
   opening a PR; CI green on exact head; squash merge to `main`.

So the “every commit green” rule is **session-hot guidance drift**, not a
codified gate. Agents following CLAUDE literally over-block recoverable
checkpoints; agents ignoring it still land correctly via squash + exact-head CI.

## Decisions

1. **Checkpoints vs review candidates.** Intermediate unpublished/branch commits
   may be recoverable checkpoints (including deliberately red TDD states). They
   are not claimed release-ready. The **one review candidate** per work unit must
   be coherent and fully qualified before acceptance.
2. **Qualification surface.** Exact PR-head CI + governed squash auto-merge remain
   the acceptance path. Do not add per-commit full-suite or default per-commit
   preflight CI jobs in this slice.
3. **Preserve TDD evidence.** Failing-first tests and repair history stay on the
   branch; squash means `main` does not need every intermediate SHA to have been
   green.
4. **Shared guidance ownership.** Cursor lands lane-owned docs, Architecture
   fixtures, changelog fragment, and an **exact Codex integration patch** for
   `CLAUDE.md` / `docs/governance/agent-contract.md` / `docs/specs/workflow.md` /
   `docs/ci/README.md`. Do not edit those shared files in this candidate.
5. **Non-squash boundary (explicit today).** Ordinary PR landings are
   squash-only (`auto-merge.yml`). **Release cut** must push the exact
   four-gate-tested SHA to `main` (App bypass); squash/rebase/merge-commit
   would rewrite that SHA — documented in the cookbook as the only current
   non-squash exception. Future cherry-pick lanes need their own documented
   boundary; do not weaken squash landings implicitly.

## Work packages

1. Change record + inventory (this document).
2. Lane-owned cookbook: `docs/cookbook/commit-qualification.md`.
3. Architecture fixture pinning hooks tiers + squash auto-merge + cookbook
   contract language.
4. Codex integration patch + landing handoff.
5. Changelog fragment; one review candidate / PR.

## Verification

- Architecture: `CommitQualificationPolicyTest` (+ existing `ProjectHooksTest`).
- No change to `ci.yml` / `tools/preflight-gates.json` in this lane.
- Shared-file wording lands via Codex applying
  `docs/change-records/FW-DELIVERY-COMMIT-POLICY-01-codex-patch.md`.

## Out of scope

- Per-commit hosted CI jobs.
- Rewriting historical branches for cosmetic green-every-SHA history.
- Weakening exact-head required checks or allowing post-merge red discovery to
  substitute for pre-acceptance qualification.

## Codex integration review

The shared contract, CLAUDE adapter, workflow spec, and CI README are integrated
in this candidate. The cookbook no longer claims to override canonical guidance
or describes a pending change. Existing checkpoint hooks, full local publication
gates, strict pinned-head/combined-state acceptance, and separately authorized
release-cut identity are preserved.

The supplied prose-locking Architecture test was removed. It pinned a temporary
handoff sentence and expected `--squash` inline in the workflow, so it failed
after #2900 correctly moved that invocation into its trusted helper. Existing
`ProjectHooksTest` and executable `DeliveryLedgerMergeGuardTest` cover the real
hook and merge behavior; release pipeline fixtures cover the release path.
No replacement tests that merely search policy wording are introduced.
