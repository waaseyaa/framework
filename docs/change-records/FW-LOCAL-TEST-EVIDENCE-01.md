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
