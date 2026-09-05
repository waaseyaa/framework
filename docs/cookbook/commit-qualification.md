# Commit qualification: checkpoints vs review candidates

Stable identity: `FW-DELIVERY-COMMIT-POLICY-01` (Framework #2903, part of #2527).

## One-sentence rule

Intermediate branch commits may be **recoverable checkpoints**; only the
**review-candidate head** must be fully qualified; `main` accepts that head via
**governed squash** (with one documented non-squash exception below).

## Tiers

| Tier | What it is | What must be true |
| --- | --- | --- |
| **Checkpoint** | Intermediate commit on a feature branch (TDD red, WIP, repair steps) | Recoverable via `bin/git`; not claimed release-ready; no stash |
| **Review candidate** | The one coherent tip SHA offered for acceptance | Exact-head CI green; design/change-record/evidence bound; one candidate per work unit |
| **Landed on `main`** | Squash merge of that candidate | Governed auto-merge (`--squash`); required checks already green on the PR head |

Do **not** claim checkpoint commits are individually release-ready. Do **not**
rewrite others’ branches to fake a green-every-SHA history.

## What hooks and CI actually enforce

| When | Gate | Full suites? |
| --- | --- | --- |
| `pre-commit` | portable paths; `composer cs-check` if staged `.php` | No |
| `pre-push` | `php bin/check-pr-preflight` (fast repo-state) | No |
| Before opening / qualifying a PR | `php bin/check-pr-preflight --full` + Unit + Integration + Architecture | Yes (local publication) |
| Hosted CI | Required ruleset checks on the **exact PR head** | Yes (candidate) |
| Merge | `auto-merge-when-green` → `gh pr merge --auto --squash` | Squash to `main` |

There are **no** per-commit full-suite or default per-commit preflight CI jobs.
Adding them would cost runtime without proving every ancestor under squash.

## Misleading guidance (pending Codex shared-file landing)

`CLAUDE.md` currently says `` `composer test` must pass before any commit. ``
That line is **not** enforced by hooks or CI and conflicts with checkpoint /
TDD practice. The Codex integration patch in
`docs/change-records/FW-DELIVERY-COMMIT-POLICY-01-codex-patch.md` replaces it
with the tiers above. Until that lands, treat this cookbook + the agent
contract’s “one review candidate / exact-head evidence” as authoritative over
that CLAUDE bullet.

## Non-squash exception (explicit)

**Ordinary PR landings** are squash-only via `.github/workflows/auto-merge.yml`.

**Release cut** (`.github/workflows/release-cut.yml`) is a **distinct supported
boundary**: it must push the **exact four-gate-tested SHA** to `main` with App
bypass. Squash / rebase / merge-commit would rewrite that SHA and break the
“tag only what was gated” invariant. That path is **not** a general license for
merge commits or cherry-picks on feature PRs.

If a future workflow (cherry-pick lane, emergency hot-fix) needs individually
qualified commits, document it here as its own boundary — do not weaken squash
landings implicitly.

## Agent habits

1. Commit checkpoints freely; never `git stash`.
2. Keep repair history on the branch; one review candidate tip.
3. Qualify the tip (`preflight --full` + three suites; CI on exact head).
4. Land only through governed auto-merge squash (or the release-cut exception).
