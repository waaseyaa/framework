# Commit qualification: checkpoints vs review candidates

Stable identity: `FW-DELIVERY-COMMIT-POLICY-01` (Framework #2903, part of #2527).

## One-sentence rule

Intermediate branch commits may be **recoverable checkpoints**; only the
**review-candidate head** needs the work package's focused qualification.
During the solo-maintainer sprint, `main` accepts reviewed batches by normal
fast-forward push or by an optional pull request. Release-cut remains the hard
exact-SHA qualification boundary.

## Tiers

| Tier | What it is | What must be true |
| --- | --- | --- |
| **Checkpoint** | Intermediate commit on a feature branch (TDD red, WIP, repair steps) | Recoverable via `bin/git`; not claimed release-ready; no stash |
| **Review candidate** | The one coherent tip SHA offered for acceptance | Required local hooks and the documented risk-based test plan; design/change-record/evidence bound; one candidate per work unit |
| **Landed on `main`** | Reviewed batch, normally fast-forwarded | Main CI is observed and failures are assigned; the head is not claimed release-ready until release-cut proves the exact SHA green |

A squash creates a new commit identity; retain both the qualified candidate and
accepted-main identities in delivery evidence.

Do **not** claim checkpoint commits are individually release-ready. Do **not**
rewrite others’ branches to fake a green-every-SHA history.

## What hooks and CI actually enforce

| When | Gate | Full suites? |
| --- | --- | --- |
| `pre-commit` | portable paths; `composer cs-check` if staged `.php` | No |
| `pre-push` | `php bin/check-pr-preflight` (fast repo-state) | No |
| Before landing a batch | Documented local test plan under [local testing policy](../local-testing-policy.md), plus required hooks | Only when justified by impact or explicit acceptance |
| Hosted CI | Feedback on the landed `main` head or an optional PR | Broad, but may be red between release tags when tracked |
| Release cut | Exact-SHA release workflow gates | Must be green before tagging or publication |

There are **no** per-commit full-suite or default per-commit preflight CI jobs.
Adding them would cost runtime without proving every ancestor under squash.

## Canonical guidance

The shared agent contract and workflow spec define this policy; this cookbook
explains it. `CLAUDE.md` points to the same checkpoint/candidate distinction.
The historical all-commits test requirement has been removed. Existing hooks
still apply to checkpoints; the policy does not authorize bypassing them.

## Non-squash exception (explicit)

**Ordinary landings** may be fast-forward batches during the solo-maintainer
sprint. Optional PR landings may still use squash where the maintainer wants a
review surface.

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
3. Execute the scoped test plan and retain its evidence. Use the canonical full
   runner when broad qualification is required; corroborate committed source
   with CI on the exact head.
4. Land the reviewed batch by a normal fast-forward push or optional PR. Never
   force-push `main`.

## Canonical local qualifier

This runner remains available for a test plan requiring full local qualification;
it is not mandatory for every PR. The [local testing policy](../local-testing-policy.md)
determines local scope. Its receipt terminology below is unchanged: a scoped
plan does not become a full-run `qualification: true` receipt. Repository
acceptance also requires review and an explicit accounting of any hosted
failure. Release acceptance requires green exact-SHA hosted gates.

```bash
php bin/qualify-candidate            # preflight --full, then Unit + Integration + Architecture on the exact HEAD
php bin/qualify-candidate --jobs=2   # explicit concurrency after preflight passes
php bin/qualify-candidate --collect-all # diagnostic override after a failing preflight
```

The default evidence directory is
`build/qualification/<sha>-<time>/receipt.json`. A successful full default run
has `verdict: qualified`, `qualification: true`, and exit 0. Custom plans,
subsets, and allowed dirty tracked trees may also exit 0, but their receipt says
`verdict: passed`, sets `qualification: false`, and names the disqualifiers.
Every other exit records why the candidate was not qualified unless the evidence
directory or receipt itself could not be written. When selected, `preflight` is a
scheduling barrier: it runs alone, and a non-pass records the held suites as `unrun`
without spending their runtime. Once preflight passes, all three suites still run even
when an earlier suite fails. `--collect-all` is the explicit diagnostic override that
schedules all selected components despite a preflight failure; it does not relax the
all-components-must-pass qualification rule.

Qualification intentionally binds HEAD, its tree, and tracked working-tree
state. Run it from an isolated worktree: untracked scratch is permitted and is
not represented in the receipt, even though untracked source can affect local
autoloading or test discovery. Hosted CI remains committed-source feedback;
release-cut is the exact-head qualification authority.
