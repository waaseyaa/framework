---
name: Migration Plan
about: Track a breaking change and its migration path for existing tenants
labels: migration
---

## Breaking Change Description

<!-- What changed and why it breaks existing deployments -->

## Affected Tenants / Configurations

<!-- Who is affected? How to detect? -->

## Migration Steps

1. <!-- Step 1 -->
2. <!-- Step 2 -->
3. <!-- Step 3 -->

## Rollback Steps

1. <!-- Rollback step 1 -->
2. <!-- Rollback step 2 -->

## Safe Toggle

<!-- Is there a feature flag or per-tenant toggle to disable/enable the new behavior? -->

## Automated Smoke Tests

- [ ] Migration smoke test added to CI
- [ ] Rollback smoke test added to CI

## Versioning Impact

- [ ] Is this change breaking under pre-v1 rules? (It may be; document it.)
- [ ] Would this require a major version bump post-v1.0?
- [ ] `VERSIONING.md` updated if compatibility rules changed?

## Acceptance Criteria

<!-- Clear, testable criteria for migration completion -->

## Estimated Effort

<!-- S / M / L -->

## Priority

<!-- P0 / P1 / P2 -->

## Labels

`migration` `p0/p1/p2`

## Milestone

<!-- milestone -->

## Dependencies

<!-- List issue numbers -->
