# Qualification preflight scheduling barrier

Stable identity: `FW-DELIVERY-QUALIFICATION-PREFLIGHT-BARRIER-01` (Framework #2527).

## Problem

`bin/qualify-candidate --jobs=N` currently admits the preflight and expensive PHPUnit
suites to the same scheduler queue. With more than one job, Unit or Integration may
start before preflight finishes; with one job, the runner still continues into every
suite after preflight has already failed. This spends the largest part of the local
qualification budget on a candidate that has already failed its mandatory preflight.

## Contract

- When the selected component set contains the canonical `preflight` component, the
  runner schedules it alone before any other selected component.
- A passed preflight opens the barrier. All remaining components then use the requested
  `--jobs` concurrency, and a suite failure does not stop the other suites from running.
- A failed, signaled, or evidence-error preflight keeps the barrier closed. Every held
  component is represented in the terminal receipt as `outcome: unrun`, with
  `termination: not_started`, null timestamps and exit status, and a reason naming the
  preflight barrier. No log or JUnit file is attributed to an unrun component.
- `--collect-all` is an explicit diagnostic override that preserves the former
  all-components scheduler. It is recorded as `runner.collect_all`; it does not weaken
  qualification because every default-plan component must still pass for
  `qualification: true`.
- An explicit subset that omits `preflight` remains a non-qualifying diagnostic subset
  and runs exactly the named components. Custom plans remain non-qualifying.
- Interrupt and source-drift behavior remain fail closed. This slice adds no docs-only
  exemption, receipt cache, gate waiver, or change to the required qualification set.

The receipt gains a new component outcome and runner field, so the receipt schema moves
from version 1 to version 2 and the runner version moves from 1.0.0 to 1.1.0. The custom
plan input remains schema version 1. A repository-wide consumer search found no
tracked reader of qualification receipts outside the runner's focused test; external
readers must reject unknown schema version 2 rather than interpret `unrun` as a failed
or completed component.

## Proof

`tests/Architecture/QualifyCandidateRunnerTest.php` uses tiny PHP children in throwaway
Git repositories to prove three scheduler boundaries without running real suites:

1. a failing preflight under `--jobs=3` never creates either expensive-component marker
   and records both held components as unrun;
2. missing required preflight JUnit is an evidence error and leaves the held suite unrun;
3. an interruption before the barrier opens leaves the held suite unrun and the run interrupted;
4. after a passing preflight, a failing suite does not prevent later suites from running;
5. `--collect-all` runs a diagnostic component despite a failed preflight and records
   the explicit override in the receipt.

## Scope

Owned implementation is limited to `bin/qualify-candidate`, its focused Architecture
test, this record, the operator cookbook paragraph, and one changelog fragment. Full
qualification and publication remain separate exact-head work.
