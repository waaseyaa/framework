# Landing-base prequalification

Status: first bounded slice (`FW-DELIVERY-LANDING-PREFLIGHT-01`, issue #2525).

`php bin/check-landing-base --declaration=FILE` is an offline advisory check
that runs before expensive candidate qualification. It reports exact Git
topology, declared-range changes, overlap, and Git's predicted textual merge
result. It does not update a branch, run a merge, authorize a merge, or qualify
the candidate. `git merge-tree --write-tree` leaves the worktree, index, HEAD,
and refs unchanged, but may write Git objects.

## Declaration contract

The JSON declaration uses `schema: "waaseyaa.landing-base.v1"` and records:

- `recorded_base`: the immutable 40-character SHA at which the landing work
  was last based;
- `current_base: {ref, sha}` and `head: {ref, sha}`: named refs plus the exact
  SHAs the caller observed;
- `unique_range: {base, head}`: immutable SHAs delimiting only this landing
  unit's commits; `head` must equal `head.sha`, `base` must be a strict ancestor
  of it, and `recorded_base` must be an ancestor of `base`;
- `contract_inputs`: exact repository paths whose bytes are contract inputs;
- `generated_outputs`: exact repository paths known to be generated outputs.

The last two lists are annotations, never substitutes for the actual Git diff.
The report intersects them with the paths Git observed, so an unchanged
declared contract input is not reported as changed and only a real conflict on
a declared generated output is a generated-output conflict.

## Fail-closed evidence

The checker refuses shallow repositories, tracked dirty state, unreadable or
disconnected commits, multiple merge bases, invalid declared ancestry, a
base/head ref that does not match its declared SHA, and declared range
prerequisites that are no longer ancestors of the current base. It snapshots
the refs and tracked state again after all topology and merge-tree work;
movement or newly dirty tracked state changes the verdict to `indeterminate`.

The report binds the exact recorded base, current base, head, declared unique
range, true merge base, behind/ahead counts, paths changed from recorded to
current base, paths in the declared unique range, live candidate paths whose
bytes still differ from current base, path overlap, conflict paths, and the
predicted tree. Behind count is informational and never changes the verdict.
An empty unique range reports no live candidate paths. Git-derived path names
are always treated as literal pathspecs.

Verdicts and exits are:

| Verdict | Exit | Meaning |
| --- | ---: | --- |
| `textual_merge_clean` | 0 | Git predicts no textual conflict; semantic combined-state correctness remains unproven. |
| `nothing_to_land` | 0 | The predicted tree equals the current-base tree. |
| `rebase_required` | 1 | Git reports textual conflicts for a range whose prerequisite ancestry is proven. |
| `indeterminate` | 2 | The evidence is incomplete, ambiguous, moved, dirty, malformed, or missing prerequisite ancestry. |

Every report says `qualification: false` and `merge_authorized: false`. For a
textual-conflict `rebase_required` report, `rebase_onto.arguments` records the
exact `<current-base> <unique-range-base> <head>` operands as advice and says
`automatic: false`; the checker executes no rebase. Missing prerequisite
ancestry is `indeterminate` and carries no rebase advice because the checker
cannot prove that those operands preserve the intended patch set.
Qualification remains `bin/qualify-candidate`, and merge authority remains the
external pinned-head governed path.

## Deferred issue scope

This slice does not enumerate hosted checks or unresolved review threads, emit
an exact hosted merge handoff, serialize or wake dependency queues, or change
CI/preflight registration. Those #2525 acceptance items need separate adapters
and review candidates. This slice is declaration-freshness prequalification
only; it cannot make a prior report a hosted freshness guarantee.
