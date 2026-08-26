# PHPUnit skip governance

Status: LIVE. Change record: `FW-CI-SKIP-01`. Forge mirror: #2568.

## Contract

PHPUnit skips are governed capability dispositions, not a general error
handling mechanism. The repository keeps global `failOnSkipped` disabled
because split-package and cross-platform tests legitimately run without some
optional capabilities. That does not permit a required hosted regression proof
to disappear.

`tools/phpunit-skip-policy.json` owns two disjoint sets:

- `required_hosted`: test files that must execute on the supported Ubuntu PHP
  8.5 lane and therefore contain zero `markTestSkipped()` calls;
- `allowed_sites`: every remaining skip, identified semantically by path,
  reason prefix, and same-reason occurrence, with a classification, explicit
  capability predicate, and reviewed rationale.

`php bin/check-phpunit-skip-policy` scans every tracked PHP test file. It fails
when a skip is new, changed, missing from the roster, duplicated, placed in a
required-hosted file, or enclosed by `catch (Throwable)`. A broad catch is
invalid even if an allowlist entry would otherwise match it. The checker prints
the required/allowed inventory and live line numbers on every run.

## Supported hosted lane

The Anthropic provider, OpenAI-compatible provider, and stream HTTP client
transport regression classes require the ordinary `ci-test-shards` Ubuntu
24.04 / PHP 8.5 profile. Local process creation and loopback TCP are part of
that lane. Failure to start their fixtures is a test error, not a skip.

## Optional capability rules

An allowed skip must test one narrow, observable predicate, such as a missing
extension/function/class/package-form file, disabled SAPI feature, root
permission semantics, unavailable symlink operation, or a declared
unavailability exception. Catching `Throwable`, `Exception`, `Error`,
`TypeError`, or another programming-failure family and converting it to a skip
is forbidden. A declared exception may cover only environmental capability
absence; all other exceptions propagate.

To add or change a skip:

1. add the exact predicate in the test;
2. add or update its roster entry with a concrete rationale;
3. run `php bin/check-phpunit-skip-policy` and the affected suite;
4. update this contract if the hosted required/optional boundary changes.

## Parent inventory and disposition

At parent `748a669`, the complete inventory is 44 sites:

- 3 invalid broad transport-start catches, reclassified as required-hosted;
- 41 legitimate optional package/platform sites retained in the governed
  roster, including the narrow `McpServerUnavailable` server-start contract.

The roster is the ongoing mechanical authority; this count is historical
evidence for the conversion and is not a volatile policy constant.

## Enforcement surfaces

- local/pre-push: `php bin/check-pr-preflight`;
- full local review: `php bin/check-pr-preflight --full`;
- hosted fast gate: `ci/verify-gates`;
- suite self-protection: `tests/Architecture/PhpUnitSkipPolicyTest.php`;
- release verification: `composer verify`.

The gate governs skip classification only. It does not replace execution of
the Unit, Integration, Architecture, random-order, coverage, or hosted
FrankenPHP lanes.
