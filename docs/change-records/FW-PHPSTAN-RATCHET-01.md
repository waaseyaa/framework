# FW-PHPSTAN-RATCHET-01 — governed PHPStan level-8 ratchet

- Parent: `e85cc1885d7dd3c0ead505423b2563c3ca9a062a`
- Contract: `docs/specs/governed-gates.md`
- Forge mirror: Framework #2873
- Authority: production-source static-analysis evidence and its future gate only

## Finding

The primary PHPStan configuration is level 5 with strict rules, additional
above-level checks, custom entity rules, and an active baseline. A
baseline-aware audit of the parent found 486 findings at level 6, 2,436 at
level 7, 2,529 at level 8, 4,605 at level 9, and 6,278 at level 10. The 93
findings added by level 8 are small enough to adjudicate, but enabling level 8
directly would also absorb 2,436 earlier findings into an unexplained backlog.

## Decision

1. Work package 1 adds an evidence-only command. It does not change the
   required PHPStan level, baseline, preflight roster, or CI.
2. Each run records the analysed Git commit, PHPStan version, requested levels,
   expanded PHPStan parameter digest, configuration/include digests, active
   baseline, and totals by level, identifier, and package.
3. The evidence contract is checkout-independent: absolute repository paths
   are normalized before hashing.
4. A comparison mode refuses evidence collected against different levels,
   analysed paths, exclusions, includes, PHPStan version, or configuration.
   A changed input may be deliberately re-recorded, but cannot pass as the same
   measurement.
5. Work package 2 may create the fail-on-new level-8 gate only after the 93-item
   increment is exported and completely adjudicated. Level 6–7 debt remains a
   separate, shrink-only artifact.

## Sequence

1. Retain negative tests for level, path, exclusion, and include drift.
2. Implement the deterministic JSON measurement and comparison command.
3. Run the parent measurement and reconcile its output with the independently
   collected totals in #2873.
4. Review the evidence before any baseline or required-gate change.

## Boundaries

No PHPStan finding is suppressed or repaired in work package 1. No required
gate, Studio delivery slice, release, deployment, or production state changes.
