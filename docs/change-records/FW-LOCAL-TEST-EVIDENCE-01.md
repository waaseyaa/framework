# LOCAL-TEST-EVIDENCE-01: proportional local qualification

Agents now discover docs/local-testing-policy.md through repository entrypoints.
The policy requires focused implementation checks, independent discriminators,
one scoped integration qualification, explicit escalation and evidence reuse
records, and a named owner for heavy local operations.

Required hooks and hosted checks are unchanged. Existing test results retain
their original identity; equivalence to a later candidate must be justified,
and is not represented as a fresh execution.

Validation: relative Markdown links in changed guidance resolve; git diff
--check passes. This documentation-only change does not justify a PHP suite or
dependency installation. Independent review examines policy consistency.

## CI repair: deterministic cache-isolation control

Run 34374251430 failed in the inherited SiteVerifyPhpunitCacheIsolationTest:
two wall-clock timings produced identical cache bytes. The control now compares
the pristine portable tree to one real unscoped PHPUnit execution and proves
that root cache files alone account for the difference. Random/fixed sleeps
are removed. The protected two-run portable-tree equality test remains.

Focused normal and random-order runs: 2 tests / 15 assertions passed. A mutant
redirecting the cache to the project root fails at the real root-cache assertion.
A control mutant retaining storage isolation fails at the required root-cache
creation assertion (with the earlier string sanity check removed for this probe).
Both mutants were restored. No production runtime behavior changes.
This repair was locally verified by the integration owner; the earlier
independent documentation review does not cover this later test delta.
