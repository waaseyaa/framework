# Access-filtered pagination contract correction

Stable change record: `access-filtered-pagination-20260825`

Parent candidate: Framework `main` at `169dced34475de5767bd6145fd3fceab811d3efd`.
Forge mirror: `waaseyaa/framework#2541`.

## Decision

`EntityQueryInterface::range()` addresses the result set a caller is allowed to
observe. With access checking enabled, offset and limit therefore apply after
the entity policy decision. A page is dense unless the authorized result set is
exhausted. With `accessCheck(false)`, range remains a raw SQL candidate window.

This intentionally replaces the earlier FR-007 candidate-window contract. That
contract exposed storage implementation details to callers, produced empty
pages before the end of the authorized result set, and made API pagination links
internally inconsistent with their access-filtered totals.

## Implementation plan

1. Replace the sparse-page regression with tests for dense first, middle, and
   final pages, plus a bypass control that retains SQL range semantics.
2. Defer SQL range whenever access checking is enabled, evaluate the complete
   policy-filtered result, then slice by the requested authorized offset/limit.
3. Keep count semantics post-access and leave system-context SQL COUNT/range
   fast paths unchanged.
4. Update the entity-query, publishing, listing, API, and admin contracts that
   describe the superseded sparse behavior.
5. Prove focused, split-suite, random-order, and governed preflight gates.

## Performance boundary

Correctness is the first slice: the existing protected/contextual paths already
defer range and establish the semantic precedent. Incremental survivor scanning
can be introduced later without changing this contract, but must preserve a
consistent read and deterministic ordering. This change does not add an opaque
cursor or a scan cap because either would weaken the accepted full-page
guarantee.

## Explicit exclusions

- No change to `accessCheck(false)` SQL pagination.
- No inaccessible total, candidate offset, or policy decision is added to an
  API response.
- No cursor API, schema migration, dependency update, or consumer workaround.
- No deployment or release action.
