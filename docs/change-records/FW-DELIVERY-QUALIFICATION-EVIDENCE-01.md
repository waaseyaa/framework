# FW-DELIVERY-QUALIFICATION-EVIDENCE-01 — local candidate qualification with trustworthy exit evidence

- Issue: `#2913` (programme `#2527`; executes the candidate-tip policy of `#2903`,
  `docs/cookbook/commit-qualification.md`)
- Authority: repository-tracked source changes; forge-neutral
- Command: `bin/qualify-candidate`

## Problem

Publishing a review candidate requires `php bin/check-pr-preflight --full` plus
the Unit, Integration and Architecture suites on one exact SHA. Every lane has
been hand-rolling a shell wrapper to keep the evidence, and the wrappers fail in
the one place that matters: exit status. During the #2901 follow-up an
over-escaped `${PIPESTATUS[0]}` was written literally into the exit files after
PHPUnit had already printed green, and all three suites had to be rerun.
`bin/test-random-order` runs the suites but stops at the first failure and keeps
no record; `check-pr-preflight` covers preflight only. This is a gap in
*executing* existing policy, not a new gate.

## Contract

### Components and reuse

The runner executes existing commands verbatim and adds none:

| id | command |
|---|---|
| `preflight` | `php bin/check-pr-preflight --full [--base=REF]` |
| `unit` / `integration` / `architecture` | `php -d memory_limit=1G vendor/bin/phpunit --testsuite <Suite> --no-coverage --log-junit <out>/<id>.junit.xml` |

`--only=<ids>` runs a subset; an all-green subset run is verdict **passed**
with disqualifier `subset` — never a qualification. `--plan=<json>`
substitutes the component table (the fixture seam, mirroring
`bin/test-random-order --plan`); the default plan is the table above. The
plan schema has **no `qualifies` key** — a custom plan cannot declare
qualification for itself, so a `--plan` that contains one is rejected outright
(exit 2, "custom plans cannot declare qualification") before any component
runs.

### Exit evidence — no shell in the loop

The runner is PHP. Each component is a `proc_open` child whose stdout and stderr
are redirected to `<out>/<id>.log` by file descriptor — there is no pipe, no
`tee`, no `PIPESTATUS`. `proc_close` yields the integer exit status; a child
that dies from a signal is recorded as `termination: signal` with the signal
number and `exit_code: null`. PHPUnit counts (`tests`, `failures`, `errors`,
`skipped`) are read from the structured junit file. Logs are retained and never
parsed for a verdict: **success is never inferred from a green line.**

### Outcomes, kept distinct

| component outcome | meaning |
|---|---|
| `passed` | exit 0 |
| `failed` | non-zero exit |
| `signaled` | terminated by a signal |
| `evidence_error` | could not spawn, log unwritable, JUnit missing, unparseable, or structurally invalid, stale log/JUnit from a previous run at the same path that could not be cleared, a zero exit contradicted by JUnit `failures`/`errors` > 0, or post-start validation such as an unknown `--only` component |

`skipped` is a **count on a passed component**, never an outcome: PHPUnit exits 0
when every test is skipped, so the receipt carries the number and the summary
prints it. Wrapper and evidence failures are their own outcome and never look
like a test failure; an `evidence_error` component carries a `reason` string.

Evidence is bound to the child that produced it: before a component starts,
any log or junit already at its path (a reused `--out`) is removed, so counts
can never come from an earlier run.

JUnit evidence accepts the PHPUnit shapes observed in retained qualification
receipts: either one `<testsuite>` root or a `<testsuites>` root with at least
one direct `<testsuite>`. Every counted suite must explicitly provide
non-negative integer `tests`, `failures`, `errors`, and `skipped` attributes.
The container does not need count attributes, nested suites are not summed a
second time, zero counts remain valid evidence, and skipped tests remain a
count rather than a failure. Missing, negative, fractional, or non-numeric
counts are an evidence error rather than silently becoming zero.

### Source binding

At start the runner records `HEAD`, `HEAD^{tree}` and `git status --porcelain
--untracked-files=no`. A dirty tracked tree is refused (exit 3) unless
`--allow-dirty`, which — if the run is otherwise all-green — produces verdict
`passed` with disqualifier `dirty_worktree`, never a qualification. After every
component and at the end the same three are re-read; any change makes the
verdict `drifted` (exit 3): mixed-source evidence is never called a
qualification.

This is intentionally a tracked-state boundary. Untracked files are excluded
so ignored build/evidence output and permitted local scratch do not disqualify
a run. Because an untracked source or test file can still affect worktree
execution through autoloading or discovery, operators must qualify from an
isolated worktree and retain exact-head hosted CI as the committed-source
corroboration. This contract does not introduce a blanket untracked-file
refusal policy.

### Qualification vs. passed

`qualification: true` iff `verdict === "qualified"`, and `verdict:
"qualified"` requires **all** of: the default plan (no `--plan`), no
`--only`, a clean tracked tree at start (no `--allow-dirty` needed), no
drift, and every component `passed`. An all-green run that fails only one of
those conditions is `verdict: "passed"` (exit 0, still green) with the
reasons named in `receipt.disqualifiers` — drawn from `custom_plan`,
`subset`, `dirty_worktree`. This is deliberate: a `--plan` of nothing but
`exit(0)` children must never be able to assert qualification for itself, so
the plan schema carries no `qualifies` key at all (a plan that declares one is
rejected, exit 2, before any component runs).

### Reusing `--out`

A pre-existing `<out>/receipt.json` from an earlier run must never remain
visible — even briefly — while a new run is in flight. Before any component is
spawned (right after the evidence directory is created/resolved and the
candidate identity is read): an existing `receipt.json` is atomically renamed
to `receipt.superseded-<utc yyyymmddThhmmssZ>-<pid>.json` (never deleted; a
rename failure is an evidence error, exit 2, no children spawned), then an
initial `receipt.json` is written atomically with `verdict: "in_progress"`,
`qualification: false`, `disqualifiers: []`, and `runner.finished_at: null`.
A runner killed by an uncatchable signal (`SIGKILL`) before any component
finishes therefore always leaves this honest `in_progress` receipt behind —
never a stale success from a previous run. The dirty-tree refusal path goes
through the same supersede-then-write sequence. The final receipt (any
verdict) overwrites the in-progress one atomically as before. The existing
per-component stale log/junit removal is unchanged.

If validation that depends on the resolved plan fails after this initial
receipt is written (for example, `--only` names an unknown component), the
receipt is finalized as `evidence_error` with a non-null `runner.finished_at`
before exit 2. A normally terminated runner therefore never leaves a permanent
`in_progress` receipt.

### Receipt

`<out>/receipt.json` (default `build/qualification/<sha12>-<utc-timestamp>/`,
under the git-ignored `/build/`), written atomically (temp + rename), schema
version 1:

```
candidate      { head, tree, branch, dirty_at_start[] }
components[]   { id, command[], log, junit?, started_at, finished_at, duration_s,
                 exit_code, termination, signal?, counts?{tests,failures,errors,skipped}, outcome, reason? }
source_check   { head_after, tree_after, drifted, changes[] }
verdict        in_progress | qualified | passed | failed | drifted | evidence_error | interrupted
qualification  bool   — true iff verdict === "qualified"
disqualifiers  list<string>  — subset of {custom_plan, subset, dirty_worktree}; non-empty only for verdict=passed
runner         { schema_version, version, php, os, jobs, started_at, finished_at }
```

If the receipt itself cannot be written the runner exits 2 and says so; it does
not print a green summary it could not record.

### Exit codes

`0` qualified or passed (see `receipt.verdict` and `receipt.disqualifiers`) ·
`1` at least one component failed or was signaled · `2` usage, spawn or
evidence-write error (including a rejected plan or a supersede failure) · `3`
dirty at start (without `--allow-dirty`) or drifted · `130` interrupted.

### Interruption

`SIGINT`/`SIGTERM` (via `pcntl` where available) are acted on while children are
still running — not only once one exits: each running child is terminated
(SIGTERM, bounded wait, then SIGKILL) and recorded as `signaled`, the receipt is
written with `verdict: interrupted`, and the runner exits 130. Stdout always
carries an explicit `qualification: true|false` line next to the verdict, so an
`--allow-dirty` or non-qualifying plan that happens to be green never reads as a
qualification. A test-only seam
(`WAASEYAA_QUALIFY_INTERRUPT_AFTER=<id>`) triggers the same path deterministically
so the proof does not depend on signal delivery or a POSIX-only lane.

### Concurrency

`--jobs=N` (default 1) runs components concurrently; each has its own log and
junit path, and the source check still binds all of them to one head and tree.
Explicit, never implied.

### Out of scope

No per-commit CI job, no new governance threshold, no second gate registry, no
edit to `tools/preflight-gates.json`, `.github/workflows/**`, `CLAUDE.md`, or
the shared cookbook — the operator text for those is supplied below for Codex to
integrate after review.

## Proof (bounded fixtures, no full suites)

`tests/Architecture/QualifyCandidateRunnerTest.php` drives the real runner with
`--plan` fixtures whose children are tiny `php -r` programs: success; child
failure; child terminated by signal; skipped counts distinct from failure;
evidence-write failure (read-only out dir → exit 2, no success claim);
interruption via the seam; candidate drift (a child mutates a tracked file →
exit 3, `qualification: false`); dirty refusal; `--only` partial; `--jobs=2`
concurrency binding both children to one head. The red state of this test
before the runner exists is the required pre-fix evidence.

Three further cases were added by the adversarial review of the first
candidate, each red against that candidate and green after the fix: a zero
exit contradicted by junit `failures=2` (was recorded `passed`, `verdict:
qualified`, `qualification: true`; now `evidence_error`, exit 2); a stale
`alpha.junit.xml`/`alpha.log` in a reused `--out` (was attributed to the run as
`tests=14316`, `qualification: true`, and the old log line was kept; now cleared
before start, missing junit → `evidence_error`); and a real `SIGINT` delivered
to the runner alone while a 20 s child sleeps (the runner waited the full
duration and recorded the child `passed` before writing `interrupted`; now the
child is terminated within ~2 s and recorded `signaled 15`, exit 130, verdict
`interrupted`).

### Red, before the runner existed

```
There were 11 failures:
1) …a_fully_passing_plan_is_a_qualification_with_numeric_evidence
bin/qualify-candidate must exist (FW-DELIVERY-QUALIFICATION-EVIDENCE-01).
Failed asserting that file ".../bin/qualify-candidate" exists.
… (all 11 cases fail the same way — the runner does not exist yet)
FAILURES!
Tests: 11, Assertions: 11, Failures: 11.
```

### Green: fixture proof and split suites

`QualifyCandidateRunnerTest` (all 11 cases): `OK (11 tests, 77 assertions)`.
`composer test`, run split: Unit `OK (14316 tests, 241336 assertions)`,
Integration `OK (2313 tests, 11778 assertions)`, Architecture
`OK (704 tests, 33544 assertions)` — the last count includes
`TestQualityInventoryTest`'s and `PhpUnitSkipPolicyTest`'s recorded-inventory
assertions updated for this change (two new classified skips; one new
classified, bounded, fixed-delay wait inside a disposable fixture child).

### Adversarial review round 2 (Codex on PR #2919): two blockers fixed

Independent review found two ways the receipt could over-claim or hide a
prior success:

1. **A custom `--plan` could self-declare qualification.** The plan schema
   carried a `qualifies` key defaulting to `true`, so a `--plan` of nothing
   but `exit(0)` children produced `qualification: true`. Fixed by dropping
   the key from the schema entirely (a plan that declares one is rejected,
   exit 2) and computing qualification structurally: `verdict: "qualified"`
   only for the default plan, the full component set, a clean tree, no
   drift, all green. Every other all-green combination is `verdict:
   "passed"` with `disqualifiers` naming why (`custom_plan`, `subset`,
   `dirty_worktree`); `--only` no longer has its own `partial` verdict.
2. **Reusing `--out` left a stale success receipt visible while a new run
   was in flight.** A runner killed (e.g. `SIGKILL`, uncatchable) after a
   prior qualified run's receipt was still on disk left that success
   readable indefinitely. Fixed by superseding (rename, never delete) any
   existing `receipt.json` and writing an honest `verdict: "in_progress"`
   receipt before any component is spawned.

Red against the pre-fix runner (`b67309ab5`):

```
There were 7 failures:
1) …a_dirty_tracked_tree_is_refused_unless_explicitly_allowed_and_then_never_qualifies
Failed asserting that two strings are identical.
-'passed' +'qualified'
2) …concurrent_components_are_all_bound_to_one_head_and_tree
-'passed' +'qualified'
3) …an_all_green_custom_plan_is_passed_but_never_a_qualification
-'passed' +'qualified'
4) …a_plan_declaring_qualifies_is_rejected
Failed asserting that 0 is identical to 2.   (the plan ran to completion instead of being rejected)
5) …a_subset_run_is_passed_with_a_subset_disqualifier_and_never_a_qualification
-'passed' +'partial'
6) …a_reused_out_directory_supersedes_the_prior_receipt_before_children_start
-'in_progress' +'qualified'   (the pre-seeded OLD receipt was still there when the runner was SIGKILLed)
7) …a_reused_out_directory_that_completes_reports_only_the_new_run
-'passed' +'qualified'
FAILURES!
Tests: 18, Assertions: 100, Failures: 7.
```

Green after the fix: `OK (18 tests, 129 assertions)`. `bin/check-phpunit-skip-policy`
unaffected (no new skips — `required_hosted=3 allowed=46 discovered=46`).
`php bin/check-pr-preflight`: `39 gate(s) run — 0 failed`.

### Adversarial review round 3 (Codex on PR #2919): evidence parsing and receipt lifecycle

Independent review of the repaired candidate found two further receipt-trust
gaps and one timestamp defect. A well-formed non-JUnit root, an empty
`<testsuites>`, and top-level suites with missing, negative, or fractional
counts were silently converted to zero or otherwise accepted. An unknown
`--only` id was validated after the initial receipt write, so a normal exit 2
left `verdict: in_progress` forever. The in-progress builder also replaced its
explicit null completion time with the current timestamp.

Five invalid-JUnit fixture cases now require `evidence_error` without counts;
the retained valid-count and skipped-count fixtures remain green. The reused-out
SIGKILL fixture requires `runner.finished_at: null`, while the invalid-selection
fixture requires a terminal `evidence_error` receipt with a non-null completion
time. Red before repair: `24 tests, 146 assertions, 7 failures`. Green after
repair: `OK (24 tests, 171 assertions)`.

### Dogfood: the runner qualifying its own tip

`php bin/qualify-candidate --jobs=1` run from the worktree against its own
committed HEAD (`43263a20ff57`, clean tree, `feat/2913-qualification-evidence-runner`):

```
passed preflight    exit=0 tests=-     failures=- errors=- skipped=- (26.04s)
passed unit          exit=0 tests=14316 failures=0 errors=0 skipped=0 (231.43s)
passed integration   exit=0 tests=2313  failures=0 errors=0 skipped=0 (205.74s)
passed architecture  exit=0 tests=704   failures=0 errors=0 skipped=0 (359.83s)
verdict: qualified
receipt: build/qualification/43263a20ff57-20260905T135719Z/receipt.json
```

`receipt.json`: `candidate.head`/`tree` match the exact committed SHA and
tree; `source_check.drifted: false`, `changes: []`; every component
`termination: "exit"` with a real numeric `exit_code`; `qualification: true`
only because `verdict: "qualified"` — the default plan (no `--plan`), no
`--only`, and `dirty_at_start: []` all held at once, so `disqualifiers: []`.
Exit code observed: `0`.

An earlier dogfood attempt against a prior tip caught a real defect in the
runner's own lane: `bin/test-quality-inventory`'s git-aware determinism scan
matched the fixed-delay `usleep` token that exists only as a string literal
inside `QualifyCandidateRunnerTest`'s `--jobs=3` fixture children, landing it
in the scanner's `unclassified` bucket once the test file was committed (this
does not run inside `check-pr-preflight`, only inside the full Architecture
suite — exactly the gap this runner exists to close). That single real
`architecture` failure (`exit=1`, `tests=704 failures=1`) is preserved as
evidence that the runner reports a genuine failure faithfully rather than
inferring success; it was fixed by classifying the file (`bin/test-quality-inventory`)
and updating the recorded count (`tests/Architecture/TestQualityInventoryTest.php`,
8 → 9), and the fix is included in this change's second commit.

## Operator guidance

```
php bin/qualify-candidate            # preflight --full + Unit + Integration + Architecture on the exact HEAD
php bin/qualify-candidate --jobs=2   # explicit concurrency
# receipt: build/qualification/<sha>-<time>/receipt.json — inspect verdict, qualification, and disqualifiers
```

This guidance is integrated in `docs/cookbook/commit-qualification.md`, including
the distinction between a qualifying default run and an exit-0 custom, subset,
or allowed-dirty pass.
