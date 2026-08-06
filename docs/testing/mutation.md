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

Two successful CI runs at commit `5ec989307` produced identical results:

| Attempt | Mutants | Killed | Escaped | Covered | MSI / covered MSI | Errors / timeouts | Mutation step |
|---|---:|---:|---:|---:|---:|---:|---:|
| 1 | 343 | 289 | 54 | 100% | 84.26% | 0 / 0 | 50s |
| 2 | 343 | 289 | 54 | 100% | 84.26% | 0 / 0 | 53s |

The 54 survivors classify into 27 diagnostic or behavior-equivalent mutations
(21 exception-message concatenations, five cache-key spellings that remain
distinct for valid entity identifiers, and the sanitizer's equivalent empty
input fast path) and 27 actionable workflow defensive-branch gaps. The latter
remain tracked in issue #2261; they are not hidden with ignore directives.

The stable evidence supports blocking floors of 84% MSI and 84% covered MSI,
leaving a small margin below the measured 84.26%. The bounded pilot job is a
merge gate. Repository-wide mutation testing is not.

Any change to the source list, test filter, mutator profile, thread count, or
Infection version resets the two-run baseline requirement.
