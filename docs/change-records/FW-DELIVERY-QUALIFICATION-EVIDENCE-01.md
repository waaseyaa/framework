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

`--only=<ids>` runs a subset and yields a **partial** receipt that is never a
qualification. `--plan=<json>` substitutes the component table (the fixture
seam, mirroring `bin/test-random-order --plan`); the default plan is the table
above.

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
| `evidence_error` | could not spawn, log unwritable, junit missing or unparseable |

`skipped` is a **count on a passed component**, never an outcome: PHPUnit exits 0
when every test is skipped, so the receipt carries the number and the summary
prints it. Wrapper and evidence failures are their own outcome and never look
like a test failure.

### Source binding

At start the runner records `HEAD`, `HEAD^{tree}` and `git status --porcelain
--untracked-files=no`. A dirty tracked tree is refused (exit 3) unless
`--allow-dirty`, which produces evidence explicitly marked `qualification:
false`. After every component and at the end the same three are re-read; any
change makes the verdict `drifted` (exit 3): mixed-source evidence is never
called a qualification.

### Receipt

`<out>/receipt.json` (default `build/qualification/<sha12>-<utc-timestamp>/`,
under the git-ignored `/build/`), written atomically (temp + rename), schema
version 1:

```
candidate   { head, tree, branch, dirty_at_start[] }
components[]{ id, command[], log, junit?, started_at, finished_at, duration_s,
              exit_code, termination, signal?, counts?{tests,failures,errors,skipped}, outcome }
source_check{ head_after, tree_after, drifted, changes[] }
verdict     qualified | failed | drifted | evidence_error | interrupted | partial
qualification  bool   — true only for verdict=qualified over the full default plan
runner      { schema_version, version, php, os, jobs, started_at, finished_at }
```

If the receipt itself cannot be written the runner exits 2 and says so; it does
not print a green summary it could not record.

### Exit codes

`0` qualified · `1` at least one component failed or was signaled · `2` usage,
spawn or evidence-write error · `3` dirty at start (without `--allow-dirty`) or
drifted · `130` interrupted.

### Interruption

`SIGINT`/`SIGTERM` (via `pcntl` where available) terminate running children,
write the receipt with `verdict: interrupted`, and exit 130. A test-only seam
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
only because `verdict: "qualified"`, the default plan's `qualifies: true`,
and `dirty_at_start: []` all held at once. Exit code observed: `0`.

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

## Operator text for shared guidance (Codex integrates)

```
php bin/qualify-candidate            # preflight --full + Unit + Integration + Architecture on the exact HEAD
php bin/qualify-candidate --jobs=2   # explicit concurrency
# receipt: build/qualification/<sha>-<time>/receipt.json — exit 0 means qualified; anything else says why
```
