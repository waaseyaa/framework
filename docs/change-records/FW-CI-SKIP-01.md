# FW-CI-SKIP-01 — governed PHPUnit skip policy

- Parent: `748a669bc216fe4c14028a3ededc828634332762`
- Parent tree: `38d10195c09fb1d05f5db7afa387b2003b795e29`
- Contract: `docs/specs/phpunit-skip-governance.md`
- Forge mirror: Framework #2568
- Authority: testing, CI policy, and affected test harnesses only

## Finding

The parent contains 44 `markTestSkipped()` call sites. Forty-one express
platform, package-form, or optional-capability predicates. Three critical
transport classes instead catch every `Throwable` raised while starting a
local server and report the programming failure as environmental absence:

- Anthropic provider transport regression proof;
- OpenAI-compatible provider transport regression proof;
- stream HTTP client transport regression proof.

Because skipped tests do not fail the ordinary suite, those three classes can
contribute no runtime evidence while hosted CI remains green.

## Decision

1. The three transport classes are required on the supported PHP 8.5 Ubuntu
   lane and contain no skip call sites.
2. Every remaining skip is classified in one semantic roster entry keyed by
   path, reason prefix, and same-reason occurrence. Each entry names its
   predicate and rationale.
3. A fast gate rejects a new, changed, missing, or duplicate roster entry and
   rejects every `Throwable`-to-skip catch even if it is rostered.
4. Narrow declared unavailability exceptions remain permitted where the
   capability is optional; unexpected exceptions propagate as test errors.
5. The gate runs in preflight, `composer verify`, hosted `ci/verify-gates`, and
   the Architecture suite.

## Sequence

1. Record this contract and retained-red gate tests.
2. Implement the scanner and classify the complete parent inventory.
3. Remove the three invalid catches and add negative controls.
4. Run focused transport tests, split Unit/Integration/Architecture suites,
   and `php bin/check-pr-preflight --full`.
5. Record exact candidate and hosted evidence in the review candidate.

## Boundaries

No production timeout behavior, release, split, publication, deployment,
secret, DNS, staging, or production state is in scope.
