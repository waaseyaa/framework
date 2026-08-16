# S1 upgrade compatibility implementation plan

**Anchor:** #2336
**Spec:** `docs/specs/s1-upgrade-compatibility.md`

## Scope

Implement the smallest framework-owned, read-only compatibility decision for
`alpha293-to-s1-v1`. Do not collect consumer evidence or perform any upgrade
effect.

## Sequence

1. Commit this specification, plan, and changelog entry with a
   `spec-reviewed:` trailer.
2. Add a focused foundation regression that names the public evaluator and
   proves ready, invalid, unsupported, and blocked precedence. Run it before
   implementation and retain the failure.
3. Add the versioned machine contract and observation fixtures.
4. Implement a pure Layer-0 evaluator and typed result under
   `packages/foundation/src/Upgrade/`. Reject malformed/unknown keys and
   return stable ordered reason codes.
5. Add `bin/check-upgrade-contract` to bind the JSON contract, fixtures,
   specification, and changelog without reading consumer or production state.
6. Run focused tests, the split Unit and Architecture suites, Composer
   validation, code style, PHPStan, package-layer, YAML/JSON, and diff gates.
7. Request independent read-only review, commit accepted corrections, push one
   draft PR, and require exact-head CI.

## Explicit deferrals

- Consumer observation collection and orchestration: `S0-SHEG-02`.
- Configuration authority defects: the CFG remediation work packages.
- Migration ledger/live-schema and rollback defects: the DB remediation work
  packages.
- Backup/restore execution: the separately authorized recovery lab.
- Releases, merges, deployments, production access, and production mutation.

