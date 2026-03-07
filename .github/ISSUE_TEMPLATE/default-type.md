---
name: Default Content Type Change
about: Propose a change to a built-in platform default (core.* types)
labels: defaults, schema, infra
---

## Summary

<!-- What is changing and why? -->

## Acceptance Criteria

- [ ] Schema change is documented and backwards-compatible (or migration task exists)
- [ ] Manifest includes `project_versioning` block
- [ ] Boot validation test updated
- [ ] API guard tested (DELETE blocked, disable with audit log works)
- [ ] CI manifest conformance check passes

## Implementation Tasks

- [ ] Update manifest under `defaults/`
- [ ] Update JSON Schema
- [ ] Update API guard if needed
- [ ] Update migration plan if breaking
- [ ] Update `VERSIONING.md` if policy changes
- [ ] Add/update tests
- [ ] Update docs

## Estimated Effort

<!-- S / M / L -->

## Priority

<!-- P0 / P1 / P2 -->

## Labels

`defaults` `schema` `infra` `versioning` `p0/p1/p2`

## Milestone

<!-- v0.1-defaults / v0.2-onboarding / v0.3-migrations -->

## Dependencies

<!-- List issue numbers -->

## Notes

<!-- UX copy, API contract snippets, sample JSON/YAML -->

## Versioning Checklist

- [ ] Does this change affect `project_versioning` in any manifest?
- [ ] Does this change avoid creating a `v1.0` tag without owner approval?
- [ ] Has `VERSIONING.md` been updated if release policy changed?
