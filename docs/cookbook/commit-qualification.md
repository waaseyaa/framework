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
| **Review candidate** | The one coherent tip SHA offered for acceptance | Full local preflight and three suites; exact-head CI green; design/change-record/evidence bound; one candidate per work unit |
| **Landed on `main`** | Squash merge of that candidate | Governed pinned-head squash auto-merge; strict required checks and combined-state custody remain enforced |

A squash creates a new commit identity; retain both the qualified candidate and
accepted-main identities in delivery evidence.

Do **not** claim checkpoint commits are individually release-ready. Do **not**
rewrite others’ branches to fake a green-every-SHA history.

## What hooks and CI actually enforce

| When | Gate | Full suites? |
| --- | --- | --- |
| `pre-commit` | portable paths; `composer cs-check` if staged `.php` | No |
| `pre-push` | `php bin/check-pr-preflight` (fast repo-state) | No |
| Before opening / qualifying a PR | `php bin/check-pr-preflight --full` + Unit + Integration + Architecture | Yes (local publication) |
| Hosted CI | Required ruleset checks on the **exact PR head** | Yes (candidate) |
| Merge | Governed workflow → `bin/enable-governed-auto-merge` (pinned-head native squash) | Squash to `main` |

There are **no** per-commit full-suite or default per-commit preflight CI jobs.
Adding them would cost runtime without proving every ancestor under squash.

## Canonical guidance

The shared agent contract and workflow spec define this policy; this cookbook
explains it. `CLAUDE.md` points to the same checkpoint/candidate distinction.
The historical all-commits test requirement has been removed. Existing hooks
still apply to checkpoints; the policy does not authorize bypassing them.

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

1. Keep recoverable checkpoints under existing hooks; never `git stash`.
2. Keep repair history on the branch; one review candidate tip.
3. Qualify the tip with the canonical runner; retain its receipt and corroborate
   the committed source with CI on the exact head.
4. Land only through governed auto-merge squash (or the release-cut exception).

## Canonical local qualifier

```bash
php bin/qualify-candidate            # preflight --full + Unit + Integration + Architecture on the exact HEAD
php bin/qualify-candidate --jobs=2   # explicit concurrency
```

The default evidence directory is
`build/qualification/<sha>-<time>/receipt.json`. A successful full default run
has `verdict: qualified`, `qualification: true`, and exit 0. Custom plans,
subsets, and allowed dirty tracked trees may also exit 0, but their receipt says
`verdict: passed`, sets `qualification: false`, and names the disqualifiers.
Every other exit records why the candidate was not qualified unless the evidence
directory or receipt itself could not be written.

Qualification intentionally binds HEAD, its tree, and tracked working-tree
state. Run it from an isolated worktree: untracked scratch is permitted and is
not represented in the receipt, even though untracked source can affect local
autoloading or test discovery. Exact-head hosted CI remains the committed-source
corroboration.
