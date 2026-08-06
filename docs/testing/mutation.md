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

The command runs 131 focused PHPUnit tests, mutates only the six named source
files, uses two workers to keep resource use predictable, and writes
`build/logs/mutation-summary.json` plus `build/logs/mutation.log`. CI retains
both files as the `mutation-pilot` artifact.

## Baseline policy

The pilot initially reports Mutation Score Indicator (MSI), covered-code MSI,
escaped mutants, uncovered mutants, errors, and runtime without enforcing a
score. After two successful CI runs on unchanged source and tests, record the
observed range and classify surviving mutants as test gaps, equivalent mutants,
or intentionally out of scope. Only then may a pull request propose stable
blocking thresholds. Repository-wide mutation testing is not a merge gate.

Any change to the source list, test filter, mutator profile, thread count, or
Infection version resets the two-run baseline requirement.
