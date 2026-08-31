# FW-CLAUDRIEL-SPLIT-COHORT-01

Tracking: #2742. Parent: `1f69359204c9d572b85da7a4c907d0c6cff4903a`.

## Contract and plan

Claudriel's installed runtime/API content differs from the parent in nine
packages: access, audit, auth, user, routing, ai-tools, mcp, relationship and
site-contract. Five cannot currently be selected for development publication.

Add only the missing auth, user, ai-tools, mcp and relationship mappings to
the reviewed resolver. Verify the whole nine-package cohort, the five individual
selections, deduplication, and fail-closed invalid/path-shaped inputs through
the real resolver. Capture the regression red before adding the mappings.

Run the three Linux suites and full preflight before publishing a candidate.
After acceptance, use the guarded workflow to publish all nine from one exact
merged-main revision and match each remote ref against its provenance. The
downstream lock/content verification completes the issue, not the code merge.

## Boundaries

No runtime code changes, new selection syntax, blanket allowlist, direct mirror
commit, release/tag, CI or permission relaxation, production migration, or Pi
deployment. Dispatcher, SHA, CI, tagged-fanout and lease safeguards are unchanged.
Do not silently change the framework provenance reporter to accept this cohort.

## Spec review

workflow.md and version-provenance.md retain their contracts. The executable
allowlist is the reviewed inventory; this record explains this bounded expansion
without introducing a second authoritative inventory or new publication policy.

## Verification evidence

Linux PHP 8.5.9, isolated worktree; each suite/preflight command retains its
own full log and exit status.

- Red before implementation: both new tests failed with the expected auth
  target refusal (16 tests / 40 assertions, two failures).
- Green after implementation and formatting: resolver and workflow-shape tests,
  19 tests / 121 assertions, exit 0.
- Unit: 13,365 tests / 237,164 assertions, first run exit 0.
- Integration: 2,254 tests / 11,191 assertions, first run exit 0.
- Architecture: 541 tests / 27,883 assertions, one skipped test, exit 0.
- Initial full preflight: only CS formatting failed; the formatter removed a
  space before the arrow-function parameter list. All other gates passed.
  Full preflight must pass again against the committed candidate.

Remote publication and downstream consumer acceptance are still pending.
