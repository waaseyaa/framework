# Project board synchronization

## Authority and scope

The organization Project **Waaseyaa Framework Roadmap** is a planning mirror
for `waaseyaa/framework`. Repository state remains authoritative. The board
does not authorize issue-label, milestone, release, merge, or deployment
changes.

Five axes remain independent:

| Axis | Authority | Board treatment |
| --- | --- | --- |
| Gate membership | `release:beta-blocker`, and the governed beta register when present | Never written from the board |
| Readiness | one readiness carrier on the issue | Mirrored to Project `Readiness` |
| Delivery lane | the issue milestone | Native Project milestone display; never rewritten by this synchronizer |
| Delivery ordering | Project `Roadmap Stage` | Board-owned; never derived from another axis |
| Release claim | `threshold:*` issue labels | Never written from the board |

Priority is independently authoritative in exactly one `priority:p0` through
`priority:p3` issue label and mirrors to Project `Priority`.

No axis is derived from another. In particular, `Roadmap Stage` does not imply
gate membership or a threshold, a threshold does not select a milestone, and a
milestone does not imply readiness or priority.

## Closed mappings

Project `Status` follows issue lifecycle: a closed issue is `Done`; an open
issue carrying `status:in-progress` is `In Progress`; every other open issue is
`Todo`. Closed issues stay on the Project as history and their Project
`Readiness` value is cleared.

The only accepted readiness mappings are current repository labels:

| Issue carrier | Project `Readiness` |
| --- | --- |
| `status:ready` | `Ready` |
| `status:in-progress` | `In Progress` |
| `status:blocked` | `Blocked` |
| `status:needs-design` | `Needs Design` |
| `portfolio:needs-validation` | `Needs Validation` |
| `status:needs-rescope` | `Needs Rescope` |
| `status:needs-triage` | `Needs Triage` |
| `portfolio:deferred` | `Deferred` |
| `needs-decision` | `Decision` |

An open issue with no readiness carrier maps to `Needs Triage`. More than one
carrier is ambiguous even when one is a `status:*` label and another is a
portfolio label. An unknown `status:*` label also poisons the axis; a known and
unknown pair never silently selects the known value. The synchronizer reports
readiness ambiguity and proposes no readiness write for that issue. Priority
behaves the same way: exactly one known priority maps; no priority or multiple
priorities is reported and is not guessed.

## Coverage and the beta invariant

Every open issue must have exactly one Project item. A missing open issue is an
intake finding and may be added by an explicitly reviewed plan. Closed issues
that are absent are not re-added. Duplicate items fail closed.

Every open issue carrying `release:beta-blocker` must have `Roadmap Stage = 0 -
Safety`. The synchronizer reports a missing or different stage but never
changes the stage or label. The reverse is intentionally false: an open Stage
0 issue without `release:beta-blocker` is valid and produces no finding.

## Operator boundary

`php bin/project-board-sync audit` is the default and performs no writes.
`plan` emits deterministic operations bound to the repository, Project id,
field ids, option ids, and a canonical source fingerprint. Live reads use a
1,000-record bound and compare Project field/item totals with the returned
collections; reaching a bound or observing truncation fails before planning.
Only open repository issues are enumerated. A Project issue absent from that
complete open set is closed, which is enough to preserve its item, set `Done`,
and clear readiness without loading unrelated closed history.
`verify-plan` re-reads the source and rejects any changed issue, item, field, or
option identity. `apply` has no unplanned mode: it requires a verified plan, a
new exclusive receipt path, and revalidation immediately before executing its
exact add, update, and clear operations. The receipt is updated after each
successful operation. A partial failure names both completed and failed work,
and the existing receipt prevents blind replay of the same invocation.

Live access uses the authenticated GitHub CLI. Fixture snapshots exercise the
same planner without network access and cannot be applied. Scheduling needs a
separately governed organization-Project credential; this contract does not
invent a repository secret or silently reuse a release credential.
