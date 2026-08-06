# Mutation-testing pilot

Waaseyaa uses Infection to measure whether focused tests detect meaningful
changes to security-sensitive behavior. The pilot is deliberately bounded to
six small framework surfaces:

- access-result composition;
- rich-text sanitization;
- canonical approval-argument fingerprints used by MCP approvals;
- workflow binding validation;
- workflow pointer-move authorization; and
- workflow state-transition authorization.

Run it with a PHP 8.5 runtime that has PCOV or Xdebug line coverage enabled:

```bash
php bin/test-mutation-pilot
```

The command runs the focused unit contracts plus the existing workflow
integration spines, mutates only the six named source files, selects only
covering tests for each mutant, uses two workers to keep resource use
predictable, and writes
`build/logs/mutation-summary.json` plus `build/logs/mutation.log`. CI retains
both files as the `mutation-pilot` artifact.

## Baseline and gate

The original two-run baseline at commit `5ec989307` established the pilot and
its conservative 84 percent floor. Issue #2261 then added behavior-focused
tests for every actionable workflow survivor. The first unchanged run of the
remediated source and test set produced:

| Baseline | Mutants | Killed | Escaped | Covered | MSI / covered MSI | Errors / timeouts | Mutation step |
|---|---:|---:|---:|---:|---:|---:|---:|
| Initial attempt 1 | 343 | 289 | 54 | 100% | 84.26% | 0 / 0 | 50s |
| Initial attempt 2 | 343 | 289 | 54 | 100% | 84.26% | 0 / 0 | 53s |
| #2261 remediated attempt 1 | 349 | 319 | 30 | 100% | 91.40% | 0 / 0 | 54s |
| #2261 remediated attempt 2 | 349 | 319 | 30 | 100% | 91.40% | 0 / 0 | 52s |

The six-mutant denominator increase is honest: the new tests reach defensive
branches that the covering-test selection could not previously associate with
a test. All 27 originally actionable workflow mutations are now killed. The 30
survivors are behavior-equivalent or deliberately diagnostic:

- 21 exception-message concatenations whose reason code and refusal behavior
  remain unchanged;
- five cache-key spellings that remain distinct over valid entity type and
  bundle identifiers;
- the sanitizer's equivalent empty-input fast-path removal;
- two coalescing reversals around an injected `WorkflowEntitySnapshotReader`,
  which is final, stateless, and has no configurable constructor; and
- one boolean rewrite over `WorkflowEntitySnapshot::workflowState`, whose
  closed type is already normalized to non-empty `string|null` by that reader.

No Infection ignore directive hides these survivors. A behavior change in any
of the guarded authorization, revision, or state-projection branches fails a
focused test.

The remediated evidence supports blocking floors of 91% MSI and 91% covered
MSI, leaving a small margin below the measured 91.40%. The bounded pilot job is
a merge gate. Repository-wide mutation testing is not. Two unchanged runs
reproduced the final result before the threshold was accepted.

Any change to the source list, test filter, mutator profile, thread count, or
Infection version resets the two-run baseline requirement.
