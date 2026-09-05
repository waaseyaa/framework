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

## Operator text for shared guidance (Codex integrates)

```
php bin/qualify-candidate            # preflight --full + Unit + Integration + Architecture on the exact HEAD
php bin/qualify-candidate --jobs=2   # explicit concurrency
# receipt: build/qualification/<sha>-<time>/receipt.json — exit 0 means qualified; anything else says why
```
