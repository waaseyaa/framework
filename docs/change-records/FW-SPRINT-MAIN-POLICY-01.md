# FW-SPRINT-MAIN-POLICY-01 - solo-maintainer sprint landing policy

- Status: accepted by maintainer direction on 2026-09-27
- Scope: Framework development landings during the current sprint
- Publication authority: unchanged; release-cut remains mandatory

## Decision

Ordinary reviewed work may fast-forward directly to `main`. Pull requests and
hosted checks remain available review and feedback surfaces, but neither is a
precondition for an ordinary development landing. Main may temporarily be red
between release tags when the failure is recorded with a repair owner and the
team continues toward a coherent green batch.

The live `main-protection` ruleset retains only branch deletion and non-fast-
forward protection. Force pushes and deletion remain prohibited.

## Retained controls

- isolated worktrees and explicit ownership;
- small attributable commits and stable change-record identities;
- focused tests and a failure discriminator appropriate to the changed risk;
- independent review for authentication, authorization, persistence,
  execution, isolation, security, and custody changes;
- normal main CI, with failures visible and assigned rather than suppressed;
- no update to main while release split or fan-out is active;
- exact-SHA release-cut gates before any tag or package publication.

## Known tradeoff

Main is an integration branch, not a continuously release-ready branch, during
this sprint. A broken main head can slow another worktree and requires explicit
coordination. This is accepted because the project has two maintainers and the
previous PR/check ceremony dominated iteration time. The exception must be
reassessed before public launch or when additional contributors join.

## Recovery

If a landed batch causes an unacceptable regression, repair it with the next
small fast-forward commit or revert the offending commit with a new commit.
Never rewrite main. A release cut refuses to proceed until the exact candidate
passes its full hosted qualification.
