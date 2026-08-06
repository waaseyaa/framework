# waaseyaa/testing

**Layer 1 - Core Data**

Focused PHPUnit 13 fixtures that exercise real Waaseyaa and Symfony contracts.
The package is intended for `require-dev`; it does not provide an application
kernel or an alternate testing framework.

## Canonical fixtures

- `MutableEntityClock` drives an `EntityClockInterface` without wall-clock waits.
- `AuthorizationPrincipalFactory` builds the real immutable decision principal.
- `EntityTypeFactory` builds shape-only entity types without importing another
  package's `autoload-dev` namespace.
- `TemporaryDirectory` owns one traversal-safe temporary filesystem tree.
- `TemporarySqliteDatabase` owns a file-backed SQLite `DatabaseInterface` and
  its sidecar files.
- `KernelServicesFixture` implements the real provider-resolution contract.
- `EntityFactory` and `EntityTypeFixtureValues` remain supported entity fixture
  builders.

HTTP and MCP request fixtures stay with their higher-layer protocol owners
because a Layer 1 package must not import Symfony's transport surface or know a
Layer 6 protocol.

## Compatibility-only surface

`WaaseyaaTestCase`, `CreatesApplication`, `InteractsWithApi`,
`InteractsWithAuth`, `InteractsWithEvents`, and `RefreshDatabase` are deprecated.
They model framework state as arrays, a no-op service bag, or raw PDO and have no
tracked consumers outside this package. They remain temporarily for source
compatibility and should not be adopted by new tests.

Package suites declared as independently runnable are listed in
`docs/specs/testing-strategy.md` and proven by `bin/test-isolated-package` in CI.
