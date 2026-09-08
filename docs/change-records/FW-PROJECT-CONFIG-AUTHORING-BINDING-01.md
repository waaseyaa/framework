# FW-PROJECT-CONFIG-AUTHORING-BINDING-01 — expected site identity at authorization

- **Issue:** [#3042](https://github.com/waaseyaa/framework/issues/3042)
- **Parent journey:** [#3037](https://github.com/waaseyaa/framework/issues/3037)
- **Status:** implemented on a local candidate; independent review, merge,
  release, and deployment are not claimed by this record.

## Decision

`project:config:authorize` accepts the optional pair
`--expected-site-manifest-digest` and `--expected-site-plan-digest`. The pair
lets a trusted orchestration layer bind authoring to the exact canonical site
identity it already evaluated without supplying a private signature policy or
altering the public authorization document.

The command requires both values together and requires lowercase SHA-256
syntax before it resolves or invokes signing custody. It continues to parse the
site manifest, compile the pure application-blueprint plan, and sign generated
configuration through `ProjectConfigAuthorizer`. Before writing stdout, it
uses `ProjectConfigAuthorization::assertMatches()` to compare the canonical
result with both expected digests. A mismatch therefore produces a refusal
with no success document. Omitting both options preserves the existing output
bytes and signing path.

This addition does not accept expected identities from the generated consumer,
change CFG-03/CFG-04 signing custody, introduce Studio policy, or change
project activation and replay semantics.
