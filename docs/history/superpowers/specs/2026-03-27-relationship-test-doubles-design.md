# Extract Shared Test Doubles for Relationship Package (#677)

## Context

The Relationship package has 3 test files (`RelationshipValidatorTest`, `RelationshipPreSaveListenerTest`, `RelationshipDeleteGuardListenerTest`) that duplicate ~350 lines of nearly identical test stubs: `StubEntityTypeManager`, `StubEntityStorage`, and `StubEntityQuery`. The stubs also have inconsistent behavior — some unused methods throw `RuntimeException`, others silently return empty values. This issue unblocks #678 (edge case coverage expansion).

## Approach

**Package-local fixtures** in `packages/relationship/tests/Fixtures/`.

Not promoted to `packages/testing/` because:
- Stubs encode domain-specific behavior (delete guard query counting, "all entities exist" assumptions)
- `packages/testing/` has zero framework deps — adding `waaseyaa/entity` would couple all consumers
- `api/` already has its own `InMemoryEntityStorage` (a fake, not a stub) — different purpose
- Three-strikes rule: promote to shared only when 3+ packages create the same double

## New Fixture Classes

### `StubEntityTypeManager`

- **Namespace**: `Waaseyaa\Relationship\Tests\Fixtures`
- **Implements**: `EntityTypeManagerInterface`
- **Constructor**: `(array $knownTypes, array $storages = [], ?\Closure $hasDefinitionOverride = null)`
  - `$knownTypes`: map of type ID → EntityType definition
  - `$storages`: map of type ID → `EntityStorageInterface` instance (test configures per-type storage)
  - `$hasDefinitionOverride`: optional callback for special-case `hasDefinition()` logic (delete guard needs to check for `'relationship'` type)
- **Behavior**:
  - `getDefinition($typeId)`: returns from `$knownTypes` or throws
  - `hasDefinition($typeId)`: delegates to override callback if set, otherwise checks `$knownTypes`
  - `getStorage($typeId)`: returns from `$storages` map, or throws if not configured
- **Unimplemented methods**: throw `\BadMethodCallException('Not implemented.')`

### `StubEntityStorage`

- **Namespace**: `Waaseyaa\Relationship\Tests\Fixtures`
- **Implements**: `EntityStorageInterface`
- **Constructor**: `(?\Closure $loadHandler = null, ?EntityQueryInterface $query = null)`
  - `$loadHandler`: `fn(int|string $id): ?EntityInterface` — controls `load()` behavior
  - `$query`: returned by `getQuery()` — defaults to `NullEntityQuery` if null
- **Behavior**:
  - `load($id)`: delegates to `$loadHandler`, returns minimal stub entity if no handler set
  - `getQuery()`: returns `$query`
- **Unimplemented methods**: throw `\BadMethodCallException('Not implemented.')`
- **Not `final`** — matches `api/InMemoryEntityStorage` convention

### `NullEntityQuery`

- **Namespace**: `Waaseyaa\Relationship\Tests\Fixtures`
- **Implements**: `EntityQueryInterface`
- **Behavior**: all chainable methods return `$this`, `execute()` returns `[]`
- Useful as default when tests don't exercise query paths

### `FixedResultEntityQuery`

- **Namespace**: `Waaseyaa\Relationship\Tests\Fixtures`
- **Implements**: `EntityQueryInterface`
- **Constructor**: `(array ...$resultSets)` — each argument is one `execute()` result
- **Behavior**: chainable methods return `$this`, successive `execute()` calls return results in order (first call → first result set, second call → second set, etc.)
- Replaces the delete guard's `$queryCallCount` tracking pattern with a cleaner sequential-result approach

## Conventions

- All unimplemented methods throw `\BadMethodCallException('Not implemented.')` — consistent, catches accidental usage
- All fixture classes are non-`final` — allows anonymous-class overrides in specific tests
- `declare(strict_types=1)` in every file
- PHPUnit 10.5 attributes (`#[CoversClass]` not needed on fixtures)

## Test File Changes

### `RelationshipValidatorTest.php`
- Remove inline `StubEntityTypeManager`, `StubEntityStorage`, `StubEntityQuery` class definitions
- Add `use Waaseyaa\Relationship\Tests\Fixtures\{StubEntityTypeManager, StubEntityStorage, NullEntityQuery}`
- Configure `StubEntityTypeManager` with known types and a `StubEntityStorage` that returns entities for valid IDs

### `RelationshipPreSaveListenerTest.php`
- Remove inline `StubEntityTypeManager`, `StubEntityStorage`, `StubEntityQuery` class definitions
- Add same `use` imports as ValidatorTest
- Same configuration pattern — stubs are nearly identical to the validator test's usage

### `RelationshipDeleteGuardListenerTest.php`
- Remove inline `StubEntityTypeManager`, `StubEntityStorage`, `StubEntityQuery` class definitions
- Add `use Waaseyaa\Relationship\Tests\Fixtures\{StubEntityTypeManager, StubEntityStorage, FixedResultEntityQuery}`
- Configure `StubEntityTypeManager` with `$hasDefinitionOverride` for `'relationship'` type check
- Use `FixedResultEntityQuery` with outbound/inbound ID arrays instead of call-count tracking

## Verification

1. `./vendor/bin/phpunit packages/relationship/tests/` — all 49 existing tests pass
2. `composer cs-check` — code style passes
3. `composer phpstan` — static analysis passes
4. Confirm no inline stub class definitions remain in the 3 test files
