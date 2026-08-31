# FW-SPLIT-AI-DEVELOPMENT-01

## Contract and scope

Permit the exact `ai-development` selection in the existing development-main
split resolver, mapping `packages/ai-development` to the existing
`waaseyaa/ai-development` mirror. The mirror is currently empty. This is a
development publication, not a tagged release or production dependency change.

Parent: `2a888f60f5b17537188514e282cf7b376ff791bf`. Tracking: #2738.

## Plan and invariants

1. Reproduce rejection of the exact requested target through the real resolver.
2. Add one explicit allowlist mapping. Test exact output, deduplication and
   rejection of path-shaped, unknown and mixed valid/invalid inputs.
3. Run release-tooling tests, the three Linux suites and full preflight.
4. Submit one review candidate; publish only from merged main through the
   existing guarded split-main workflow with provenance.
5. Verify the first mirror publication and a disposable require-dev consumer
   resolution. Keep the issue open until publication evidence is complete.

Dispatcher authorization, current-main SHA checks, green CI, release-overlap
refusal, lease-protected push, package manifest and dependency graph are unchanged.
No direct mirror commit, wildcard allowlist, tag, release, or production install.

## Spec impact

Reviewed workflow.md and version-provenance.md: their existing reviewed-target,
development-publication and monorepo/split identity contracts are unchanged.
The selected package inventory lives in the executable allowlist, not a second
documentation list.

## Verification

PHP 8.5.9, Linux, isolated worktree based on the parent above. Each suite and
preflight command retained its own full log and exit status.

- Red: the new exact-target regression failed with exit 2 and
  `Split-main target 'ai-development' is not allowlisted.` before the mapping.
- Green: resolver plus workflow-shape tests, 17 tests / 58 assertions, exit 0.
- Unit: 13,365 tests / 237,164 assertions, exit 0 on the first run.
- Integration: 2,252 tests / 11,128 assertions, exit 0.
- Architecture: 541 tests / 27,883 assertions, one skipped test, exit 0.
- Full preflight: 39 gates, zero failures, exit 0.

Publication and consumer-resolution evidence remain pending; code acceptance
alone does not complete #2738.
