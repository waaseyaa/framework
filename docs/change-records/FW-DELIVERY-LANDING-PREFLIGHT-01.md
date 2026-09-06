# FW-DELIVERY-LANDING-PREFLIGHT-01 — exact landing-base prequalification

Status: IMPLEMENTED / PENDING REVIEW
Forge mirror: #2525.
Parent commit: `b701d5ad4693f6f91d3e9c298b706c19ebd77f02`.

## Problem

`bin/qualify-candidate` binds the candidate head, tree, and tracked worktree
state, but it does not establish whether current main moved into a conflict or
whether the branch now has nothing left to land. Discovering that only after
the full qualification suites wastes the most expensive part of the local
publication loop. Branch age is not a safe proxy: main may advance through an
unrelated evidence-only append while the candidate remains textually clean.

## Decision and slice

Add one offline, advisory command before qualification. A machine-readable
declaration binds exact recorded-base/current-base/head objects, named live
refs, a strict historical unique range, and optional exact path annotations for
contract inputs and generated outputs. The checker uses repository Git with its
repository-selecting environment scrubbed, requires complete unambiguous
history and a clean tracked worktree, computes actual path sets, and asks
`merge-tree --write-tree --name-only` for Git's textual merge prediction.

The vocabulary deliberately avoids `landable`: `textual_merge_clean` records a
bounded Git fact and also records that semantic correctness is unproven. Every
outcome carries `qualification: false` and `merge_authorized: false`. The tool
never rebases, merges, updates refs, changes main, or changes merge authority.
Behind count is evidence only and cannot fail a candidate.

No shared preflight roster, CI workflow, governed-gates spec, or merge adapter
is changed. The durable command contract lives in
`docs/specs/landing-base-prequalification.md` rather than expanding the shared
workflow spec during parallel delivery work.

## Proof

The regression was captured first: all eight initial tests failed because
`bin/check-landing-base` did not exist (8 tests, 109 assertions). The real
implementation then passed disposable Git histories covering an unchanged
linear base, harmless main drift, a declared stacked suffix, clean same-path
overlap, textual conflict, generated-output conflict, equivalent cherry-pick,
rewritten base, invalid declared ancestry, moved ref, tracked dirty state,
disconnected history, criss-cross multiple merge bases, and a shallow clone.

Exact final verification is recorded after review repairs. Tests use the real
command, real commits and refs, and the repository's `bin/git` wrapper; no
mocked topology decides a verdict.

Working-tree candidate verification against parent
`b701d5ad4693f6f91d3e9c298b706c19ebd77f02`:

- `php -d memory_limit=1G ./vendor/bin/phpunit tests/Architecture/LandingBasePreflightTest.php --no-coverage` — 11 tests, 235 assertions, green;
- `composer cs-check` — 3,110 files checked, no fixable files;
- `php bin/changelog-fragments validate` — 85 fragments valid;
- `bash tools/drift-detector.sh --include-worktree origin/main` — no affected existing contract;
- `php bin/check-pr-preflight` — 40 default-profile gates, zero failures.

No commit, push, merge, main update, hosted handoff, or queue mutation was
performed. Candidate SHA and hosted evidence remain pending review/publication.

## Residual acceptance

Issue #2525 remains open. Hosted exact-head handoff (required checks, unresolved
threads, and match-head command) and direct-next dependency queue wake are
deferred to separate candidates. A local prequalification receipt is not a
hosted freshness guarantee and does not satisfy those acceptance items.

### Independent review repairs

Root review reproduced four failing assertions covering missing stacked
prerequisites, rewritten bases, empty-range false paths, and glob-like filenames.
The command now refuses unresolved prerequisite ancestry without rebase advice,
preserves empty path sets, and uses literal Git pathspecs. These are bounded
report-correctness repairs; qualification and merge authorization remain false.
