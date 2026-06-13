# Relationship Test Double Extraction Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract ~350 lines of duplicated test stubs from 3 Relationship test files into shared fixtures in `packages/relationship/tests/Fixtures/`, with consistent error behavior.

**Architecture:** Four fixture classes (`NullEntityQuery`, `FixedResultEntityQuery`, `StubEntityStorage`, `StubEntityTypeManager`) extracted to a Fixtures directory, replacing 9 inline stub classes across 3 test files. All unimplemented methods throw `\BadMethodCallException`. Fixture classes are non-final.

**Tech Stack:** PHP 8.4, PHPUnit 10.5, Waaseyaa entity interfaces

---

### Task 1: Create NullEntityQuery fixture

**Files:**
- Create: `packages/relationship/tests/Fixtures/NullEntityQuery.php`

This is the simplest fixture — no-op implementation of `EntityQueryInterface`. Used by `StubEntityStorage` as default query and directly by Validator/PreSave tests.

- [ ] **Step 1: Create the fixture file**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship\Tests\Fixtures;

use Waaseyaa\Entity\Storage\EntityQueryInterface;

/**
 * No-op EntityQuery — all chainable methods return $this, execute() returns [].
 *
 * @internal Test double for Relationship package tests.
 */
class NullEntityQuery implements EntityQueryInterface
{
    public function condition(string $field, mixed $value, string $operator = '='): static { return $this; }
    public function exists(string $field): static { return $this; }
    public function notExists(string $field): static { return $this; }
    public function sort(string $field, string $direction = 'ASC'): static { return $this; }
    public function range(int $offset, int $limit): static { return $this; }
    public function count(): static { return $this; }
    public function accessCheck(bool $check = true): static { return $this; }
    public function execute(): array { return []; }
}
```

- [ ] **Step 2: Verify the file passes static analysis**

Run: `composer phpstan`
Expected: No new errors

- [ ] **Step 3: Commit**

```bash
git add packages/relationship/tests/Fixtures/NullEntityQuery.php
git commit -m "test(#677): add NullEntityQuery fixture for Relationship package"
```

---

### Task 2: Create FixedResultEntityQuery fixture

**Files:**
- Create: `packages/relationship/tests/Fixtures/FixedResultEntityQuery.php`

Returns preconfigured result sets on successive `execute()` calls. Replaces `DeleteGuardStubQuery` and the `$queryCallCount` tracking in `DeleteGuardStubStorage`.

- [ ] **Step 1: Create the fixture file**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship\Tests\Fixtures;

use Waaseyaa\Entity\Storage\EntityQueryInterface;

/**
 * Returns preconfigured result sets on successive execute() calls.
 *
 * Usage:
 *   new FixedResultEntityQuery([1, 2], [3, 4])
 *   // First execute() returns [1, 2], second returns [3, 4], subsequent return []
 *
 * @internal Test double for Relationship package tests.
 */
class FixedResultEntityQuery implements EntityQueryInterface
{
    private int $callCount = 0;

    /** @param list<array<int|string>> $resultSets Each argument is one execute() result */
    public function __construct(private readonly array $resultSets) {}

    public function condition(string $field, mixed $value, string $operator = '='): static { return $this; }
    public function exists(string $field): static { return $this; }
    public function notExists(string $field): static { return $this; }
    public function sort(string $field, string $direction = 'ASC'): static { return $this; }
    public function range(int $offset, int $limit): static { return $this; }
    public function count(): static { return $this; }
    public function accessCheck(bool $check = true): static { return $this; }

    public function execute(): array
    {
        $index = $this->callCount++;
        return $this->resultSets[$index] ?? [];
    }
}
```

- [ ] **Step 2: Verify static analysis**

Run: `composer phpstan`
Expected: No new errors

- [ ] **Step 3: Commit**

```bash
git add packages/relationship/tests/Fixtures/FixedResultEntityQuery.php
git commit -m "test(#677): add FixedResultEntityQuery fixture for Relationship package"
```

---

### Task 3: Create StubEntityStorage fixture

**Files:**
- Create: `packages/relationship/tests/Fixtures/StubEntityStorage.php`

Configurable storage stub. `load()` delegates to a closure (default: returns a minimal entity). `getQuery()` returns a provided query (default: `NullEntityQuery`). All unused methods throw `\BadMethodCallException`.

- [ ] **Step 1: Create the fixture file**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship\Tests\Fixtures;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;

/**
 * Configurable entity storage stub.
 *
 * @internal Test double for Relationship package tests.
 */
class StubEntityStorage implements EntityStorageInterface
{
    private readonly \Closure $loadHandler;

    /**
     * @param ?\Closure(int|string): ?EntityInterface $loadHandler Controls load() behavior.
     *        Default: returns a minimal stub entity for any ID.
     * @param ?EntityQueryInterface $query Returned by getQuery(). Default: NullEntityQuery.
     * @param string $entityTypeId Returned by getEntityTypeId().
     */
    public function __construct(
        ?\Closure $loadHandler = null,
        private readonly ?EntityQueryInterface $query = null,
        private readonly string $entityTypeId = 'node',
    ) {
        $this->loadHandler = $loadHandler ?? static function (int|string $id): EntityInterface {
            return new class($id) implements EntityInterface {
                public function __construct(private readonly int|string $id) {}
                public function id(): int|string|null { return $this->id; }
                public function uuid(): string { return ''; }
                public function label(): string { return 'test'; }
                public function getEntityTypeId(): string { return 'node'; }
                public function bundle(): string { return 'default'; }
                public function isNew(): bool { return false; }
                public function get(string $name): mixed { return null; }
                public function set(string $name, mixed $value): static { return $this; }
                public function toArray(): array { return []; }
                public function language(): string { return 'en'; }
            };
        };
    }

    public function load(int|string $id): ?EntityInterface
    {
        return ($this->loadHandler)($id);
    }

    public function getQuery(): EntityQueryInterface
    {
        return $this->query ?? new NullEntityQuery();
    }

    public function getEntityTypeId(): string
    {
        return $this->entityTypeId;
    }

    public function create(array $values = []): EntityInterface
    {
        throw new \BadMethodCallException('Not implemented.');
    }

    public function loadByKey(string $key, mixed $value): ?EntityInterface
    {
        throw new \BadMethodCallException('Not implemented.');
    }

    public function loadMultiple(array $ids = []): array
    {
        throw new \BadMethodCallException('Not implemented.');
    }

    public function save(EntityInterface $entity): int
    {
        throw new \BadMethodCallException('Not implemented.');
    }

    public function delete(array $entities): void
    {
        throw new \BadMethodCallException('Not implemented.');
    }
}
```

- [ ] **Step 2: Verify static analysis**

Run: `composer phpstan`
Expected: No new errors

- [ ] **Step 3: Commit**

```bash
git add packages/relationship/tests/Fixtures/StubEntityStorage.php
git commit -m "test(#677): add StubEntityStorage fixture for Relationship package"
```

---

### Task 4: Create StubEntityTypeManager fixture

**Files:**
- Create: `packages/relationship/tests/Fixtures/StubEntityTypeManager.php`

Consolidates 3 near-identical manager stubs. `$knownTypes` controls `hasDefinition()`. `$storage` is returned by `getStorage()`. Optional `$hasDefinitionOverride` for the delete guard's `'relationship'` special case.

- [ ] **Step 1: Create the fixture file**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship\Tests\Fixtures;

use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;

/**
 * Configurable EntityTypeManager stub.
 *
 * @internal Test double for Relationship package tests.
 */
class StubEntityTypeManager implements EntityTypeManagerInterface
{
    /**
     * @param list<string> $knownTypes Entity type IDs that hasDefinition() returns true for.
     * @param ?EntityStorageInterface $storage Returned by getStorage(). Default: new StubEntityStorage().
     * @param ?\Closure(string): bool $hasDefinitionOverride Overrides hasDefinition() when set.
     */
    public function __construct(
        private readonly array $knownTypes = [],
        private readonly ?EntityStorageInterface $storage = null,
        private readonly ?\Closure $hasDefinitionOverride = null,
    ) {}

    public function getDefinition(string $entityTypeId): EntityTypeInterface
    {
        return new class($entityTypeId) implements EntityTypeInterface {
            public function __construct(private readonly string $id) {}
            public function id(): string { return $this->id; }
            public function getLabel(): string { return $this->id; }
            public function getClass(): string { return ''; }
            public function getStorageClass(): string { return ''; }
            public function getKeys(): array { return ['id' => 'id', 'uuid' => 'uuid']; }
            public function isRevisionable(): bool { return false; }
            public function getRevisionDefault(): bool { return false; }
            public function isTranslatable(): bool { return false; }
            public function getBundleEntityType(): ?string { return null; }
            public function getConstraints(): array { return []; }
            public function getFieldDefinitions(): array { return []; }
            public function getGroup(): ?string { return null; }
            public function getDescription(): ?string { return null; }
        };
    }

    public function hasDefinition(string $entityTypeId): bool
    {
        if ($this->hasDefinitionOverride !== null) {
            return ($this->hasDefinitionOverride)($entityTypeId);
        }

        return in_array($entityTypeId, $this->knownTypes, true);
    }

    public function getStorage(string $entityTypeId): EntityStorageInterface
    {
        return $this->storage ?? new StubEntityStorage();
    }

    public function getDefinitions(): array
    {
        throw new \BadMethodCallException('Not implemented.');
    }

    public function registerEntityType(EntityTypeInterface $type): void
    {
        throw new \BadMethodCallException('Not implemented.');
    }

    public function registerCoreEntityType(EntityTypeInterface $type): void
    {
        throw new \BadMethodCallException('Not implemented.');
    }
}
```

- [ ] **Step 2: Verify static analysis**

Run: `composer phpstan`
Expected: No new errors

- [ ] **Step 3: Commit**

```bash
git add packages/relationship/tests/Fixtures/StubEntityTypeManager.php
git commit -m "test(#677): add StubEntityTypeManager fixture for Relationship package"
```

---

### Task 5: Refactor RelationshipValidatorTest to use shared fixtures

**Files:**
- Modify: `packages/relationship/tests/Unit/RelationshipValidatorTest.php`

Remove 92 lines of inline stubs (lines 432-528). Replace with imports from Fixtures namespace. Update `makeValidator()` to use shared `StubEntityTypeManager`. Tests that pass `ValidatorStubEntityTypeManager(['node'])` become `new StubEntityTypeManager(['node'])`.

- [ ] **Step 1: Run existing tests to confirm green baseline**

Run: `./vendor/bin/phpunit packages/relationship/tests/Unit/RelationshipValidatorTest.php`
Expected: 24 tests, 24 assertions, OK

- [ ] **Step 2: Replace imports and remove inline stubs**

Replace the `use` statements and class body. The test class changes:

1. Add fixture imports:
```php
use Waaseyaa\Relationship\Tests\Fixtures\StubEntityTypeManager;
```

2. Update `makeValidator()`:
```php
private function makeValidator(?EntityTypeManagerInterface $manager = null): RelationshipValidator
{
    return new RelationshipValidator($manager ?? new StubEntityTypeManager([]));
}
```

3. Update all `new ValidatorStubEntityTypeManager(...)` calls to `new StubEntityTypeManager(...)`:
   - Line 178: `$manager = new StubEntityTypeManager(['node']);`
   - Line 265: `$manager = new StubEntityTypeManager(['node']);`
   - Line 307: `$manager = new StubEntityTypeManager(['node']);`
   - Line 357: `$manager = new StubEntityTypeManager([]);`
   - Line 408: `$manager = new StubEntityTypeManager(['node']);`

4. Delete the entire test doubles section (lines 431-528): `ValidatorStubEntityTypeManager`, `ValidatorStubEntityStorage`, `ValidatorStubEntityQuery` class definitions.

- [ ] **Step 3: Run tests to verify no regressions**

Run: `./vendor/bin/phpunit packages/relationship/tests/Unit/RelationshipValidatorTest.php`
Expected: 24 tests, 24 assertions, OK

- [ ] **Step 4: Commit**

```bash
git add packages/relationship/tests/Unit/RelationshipValidatorTest.php
git commit -m "test(#677): refactor RelationshipValidatorTest to use shared fixtures"
```

---

### Task 6: Refactor RelationshipPreSaveListenerTest to use shared fixtures

**Files:**
- Modify: `packages/relationship/tests/Unit/RelationshipPreSaveListenerTest.php`

Remove 89 lines of inline stubs (lines 90-182). Replace with imports from Fixtures namespace.

- [ ] **Step 1: Run existing tests to confirm green baseline**

Run: `./vendor/bin/phpunit packages/relationship/tests/Unit/RelationshipPreSaveListenerTest.php`
Expected: 3 tests, OK

- [ ] **Step 2: Replace imports and remove inline stubs**

1. Add fixture import:
```php
use Waaseyaa\Relationship\Tests\Fixtures\StubEntityTypeManager;
```

2. Update all `new PreSaveStubEntityTypeManager(...)` calls to `new StubEntityTypeManager(...)`:
   - Line 39: `$manager = new StubEntityTypeManager(['node']);`
   - Line 61: `$manager = new StubEntityTypeManager(['node']);`
   - Line 80: `$manager = new StubEntityTypeManager([]);`

3. Delete the entire test doubles section (lines 89-182): `PreSaveStubEntityTypeManager`, `PreSaveStubEntityStorage`, `PreSaveStubEntityQuery` class definitions.

- [ ] **Step 3: Run tests to verify no regressions**

Run: `./vendor/bin/phpunit packages/relationship/tests/Unit/RelationshipPreSaveListenerTest.php`
Expected: 3 tests, OK

- [ ] **Step 4: Commit**

```bash
git add packages/relationship/tests/Unit/RelationshipPreSaveListenerTest.php
git commit -m "test(#677): refactor RelationshipPreSaveListenerTest to use shared fixtures"
```

---

### Task 7: Refactor RelationshipDeleteGuardListenerTest to use shared fixtures

**Files:**
- Modify: `packages/relationship/tests/Unit/RelationshipDeleteGuardListenerTest.php`

This is the most complex refactor. The delete guard's `StubEntityTypeManager` has a `$hasRelationshipType` flag and its storage uses `$queryCallCount` to return different results for outbound vs inbound queries. Replace with `StubEntityTypeManager` + `$hasDefinitionOverride` closure and `FixedResultEntityQuery`.

- [ ] **Step 1: Run existing tests to confirm green baseline**

Run: `./vendor/bin/phpunit packages/relationship/tests/Unit/RelationshipDeleteGuardListenerTest.php`
Expected: 8 tests, OK

- [ ] **Step 2: Replace imports and remove inline stubs**

1. Add fixture imports:
```php
use Waaseyaa\Relationship\Tests\Fixtures\FixedResultEntityQuery;
use Waaseyaa\Relationship\Tests\Fixtures\StubEntityStorage;
use Waaseyaa\Relationship\Tests\Fixtures\StubEntityTypeManager;
```

2. Create a private helper to build the manager (replaces 3 different `new DeleteGuardStubEntityTypeManager(...)` patterns):

```php
/**
 * @param list<int|string> $outboundIds IDs returned for first query (outbound)
 * @param list<int|string> $inboundIds  IDs returned for second query (inbound)
 */
private function makeManager(
    array $outboundIds = [],
    array $inboundIds = [],
    bool $hasRelationshipType = true,
): EntityTypeManagerInterface {
    $query = new FixedResultEntityQuery([$outboundIds, $inboundIds]);
    $storage = new StubEntityStorage(
        loadHandler: static fn () => null,
        query: $query,
        entityTypeId: 'relationship',
    );

    $hasDefinitionOverride = static function (string $typeId) use ($hasRelationshipType): bool {
        return $typeId === 'relationship' ? $hasRelationshipType : true;
    };

    return new StubEntityTypeManager(
        knownTypes: [],
        storage: $storage,
        hasDefinitionOverride: $hasDefinitionOverride,
    );
}
```

3. Update all test methods. Each `new DeleteGuardStubEntityTypeManager(linkedIds: [...])` becomes `$this->makeManager(outboundIds: [...])`:

   - `ignores_non_guarded_entity_types`: `$manager = $this->makeManager();`
   - `ignores_entities_with_null_id`: `$manager = $this->makeManager();`
   - `allows_deletion_when_no_linked_relationships`: `$manager = $this->makeManager();`
   - `blocks_deletion_when_relationships_exist`: `$manager = $this->makeManager(outboundIds: [10, 20, 30]);`
   - `exception_message_contains_sorted_relationship_ids`: `$manager = $this->makeManager(outboundIds: [30, 10, 20]);`
   - `defaults_to_guarding_node_entity_type`: `$manager = $this->makeManager(outboundIds: [99]);`
   - `skips_when_relationship_type_not_defined`: `$manager = $this->makeManager(hasRelationshipType: false);`
   - `deduplicates_outbound_and_inbound_relationship_ids`: `$manager = $this->makeManager(outboundIds: [5], inboundIds: [5]);`

4. Delete the entire test doubles section (lines 149-251): `DeleteGuardStubEntityTypeManager`, `DeleteGuardStubStorage`, `DeleteGuardStubQuery` class definitions.

- [ ] **Step 3: Run tests to verify no regressions**

Run: `./vendor/bin/phpunit packages/relationship/tests/Unit/RelationshipDeleteGuardListenerTest.php`
Expected: 8 tests, OK

- [ ] **Step 4: Commit**

```bash
git add packages/relationship/tests/Unit/RelationshipDeleteGuardListenerTest.php
git commit -m "test(#677): refactor RelationshipDeleteGuardListenerTest to use shared fixtures"
```

---

### Task 8: Final verification

- [ ] **Step 1: Run full Relationship test suite**

Run: `./vendor/bin/phpunit packages/relationship/tests/`
Expected: 49 tests, all passing

- [ ] **Step 2: Run code style check**

Run: `composer cs-check`
Expected: No violations

- [ ] **Step 3: Run static analysis**

Run: `composer phpstan`
Expected: No new errors

- [ ] **Step 4: Verify no inline stub classes remain**

Search for any remaining inline stub classes in the 3 refactored files:
```bash
grep -n 'class.*Stub.*implements' packages/relationship/tests/Unit/RelationshipValidatorTest.php packages/relationship/tests/Unit/RelationshipPreSaveListenerTest.php packages/relationship/tests/Unit/RelationshipDeleteGuardListenerTest.php
```
Expected: No matches

- [ ] **Step 5: Verify fixture files exist**

```bash
ls packages/relationship/tests/Fixtures/
```
Expected: `FixedResultEntityQuery.php  NullEntityQuery.php  StubEntityStorage.php  StubEntityTypeManager.php`
