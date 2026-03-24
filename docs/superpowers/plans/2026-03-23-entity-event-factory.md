# EntityEventFactory Extraction Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract `new EntityEvent()` calls from `SqlEntityStorage` and `EntityRepository` into an injectable `EntityEventFactoryInterface`.

**Architecture:** Define the interface + default impl in `packages/entity` (layer 1, owns `EntityEvent`). Inject into both storage classes in `packages/entity-storage` via optional constructor param with default. All 11 direct instantiations become factory calls.

**Tech Stack:** PHP 8.4, PHPUnit 10.5, Symfony EventDispatcher

**Spec:** `docs/superpowers/specs/2026-03-23-entity-event-factory-design.md`

---

### File Map

| Action | File | Responsibility |
|--------|------|---------------|
| Create | `packages/entity/src/Event/EntityEventFactoryInterface.php` | Contract for creating entity events |
| Create | `packages/entity/src/Event/DefaultEntityEventFactory.php` | Default impl — `new EntityEvent(...)` |
| Create | `packages/entity/tests/Unit/Event/DefaultEntityEventFactoryTest.php` | Unit test for default factory |
| Modify | `packages/entity-storage/src/SqlEntityStorage.php` | Inject factory, replace 4 `new EntityEvent()` calls |
| Modify | `packages/entity-storage/src/EntityRepository.php` | Inject factory, replace 7 `new EntityEvent()` calls |
| Modify | `packages/entity-storage/tests/Unit/SqlEntityStorageTest.php` | Add test for custom factory injection |
| Modify | `packages/entity-storage/tests/Unit/EntityRepositoryTest.php` | Add test for custom factory injection |

---

### Task 1: Create EntityEventFactoryInterface

**Files:**
- Create: `packages/entity/src/Event/EntityEventFactoryInterface.php`

- [ ] **Step 1: Create the interface**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Event;

use Waaseyaa\Entity\EntityInterface;

interface EntityEventFactoryInterface
{
    public function create(
        EntityInterface $entity,
        ?EntityInterface $originalEntity = null,
    ): EntityEvent;
}
```

- [ ] **Step 2: Run tests to verify nothing breaks**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: 5131 tests, all pass

- [ ] **Step 3: Commit**

```bash
git add packages/entity/src/Event/EntityEventFactoryInterface.php
git commit -m "feat(#597): add EntityEventFactoryInterface contract"
```

---

### Task 2: Create DefaultEntityEventFactory with TDD

**Files:**
- Create: `packages/entity/tests/Unit/Event/DefaultEntityEventFactoryTest.php`
- Create: `packages/entity/src/Event/DefaultEntityEventFactory.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Event\DefaultEntityEventFactory;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEventFactoryInterface;
use Waaseyaa\Entity\EntityInterface;

#[CoversClass(DefaultEntityEventFactory::class)]
final class DefaultEntityEventFactoryTest extends TestCase
{
    #[Test]
    public function implementsInterface(): void
    {
        $factory = new DefaultEntityEventFactory();
        $this->assertInstanceOf(EntityEventFactoryInterface::class, $factory);
    }

    #[Test]
    public function createReturnsEntityEvent(): void
    {
        $entity = $this->createStub(EntityInterface::class);
        $factory = new DefaultEntityEventFactory();

        $event = $factory->create($entity);

        $this->assertInstanceOf(EntityEvent::class, $event);
        $this->assertSame($entity, $event->entity);
        $this->assertNull($event->originalEntity);
    }

    #[Test]
    public function createPassesOriginalEntity(): void
    {
        $entity = $this->createStub(EntityInterface::class);
        $original = $this->createStub(EntityInterface::class);
        $factory = new DefaultEntityEventFactory();

        $event = $factory->create($entity, $original);

        $this->assertSame($entity, $event->entity);
        $this->assertSame($original, $event->originalEntity);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter DefaultEntityEventFactoryTest`
Expected: FAIL — class `DefaultEntityEventFactory` not found

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Event;

use Waaseyaa\Entity\EntityInterface;

final class DefaultEntityEventFactory implements EntityEventFactoryInterface
{
    public function create(
        EntityInterface $entity,
        ?EntityInterface $originalEntity = null,
    ): EntityEvent {
        return new EntityEvent($entity, $originalEntity);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter DefaultEntityEventFactoryTest`
Expected: 3 tests, all PASS

- [ ] **Step 5: Commit**

```bash
git add packages/entity/src/Event/DefaultEntityEventFactory.php \
       packages/entity/tests/Unit/Event/DefaultEntityEventFactoryTest.php
git commit -m "feat(#597): add DefaultEntityEventFactory implementation"
```

---

### Task 3: Inject factory into SqlEntityStorage and replace calls

**Files:**
- Modify: `packages/entity-storage/src/SqlEntityStorage.php` (lines 43-54, 130, 191, 210, 233)
- Modify: `packages/entity-storage/tests/Unit/SqlEntityStorageTest.php`

- [ ] **Step 1: Write a test verifying custom factory is called**

Add to `SqlEntityStorageTest.php`:

```php
public function testSaveUsesInjectedEventFactory(): void
{
    $entity = new TestStorageEntity(
        values: ['id' => '99', 'label' => 'Factory Test', 'bundle' => 'article', 'langcode' => 'en'],
        entityTypeId: 'test_entity',
        entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
    );
    $entity->enforceIsNew(true);

    $factoryCalled = false;
    $factory = new class($factoryCalled) implements EntityEventFactoryInterface {
        public function __construct(private bool &$called) {}
        public function create(EntityInterface $entity, ?EntityInterface $originalEntity = null): EntityEvent
        {
            $this->called = true;
            return new EntityEvent($entity, $originalEntity);
        }
    };

    $storage = new SqlEntityStorage(
        $this->entityType,
        $this->database,
        $this->eventDispatcher,
        eventFactory: $factory,
    );

    $storage->save($entity);
    $this->assertTrue($factoryCalled, 'Custom event factory should be called during save');
}
```

Note: `SqlEntityStorageTest` uses `testMethodName()` convention (no `#[Test]` attribute). The `$this->entityType`, `$this->database`, `$this->eventDispatcher` come from `setUp()`. Add `use` imports for `EntityEventFactoryInterface`, `EntityEvent`, and `EntityInterface` at the top of the test file.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter saveUsesInjectedEventFactory`
Expected: FAIL — unknown named parameter `eventFactory`

- [ ] **Step 3: Modify SqlEntityStorage constructor**

In `SqlEntityStorage.php`, add the factory parameter and property:

```php
use Waaseyaa\Entity\Event\DefaultEntityEventFactory;
use Waaseyaa\Entity\Event\EntityEventFactoryInterface;

// In constructor:
public function __construct(
    private readonly EntityTypeInterface $entityType,
    private readonly DatabaseInterface $database,
    private readonly EventDispatcherInterface $eventDispatcher,
    ?LoggerInterface $logger = null,
    ?EntityEventFactoryInterface $eventFactory = null,
) {
    // ... existing assignments ...
    $this->eventFactory = $eventFactory ?? new DefaultEntityEventFactory();
}

// Add property:
private readonly EntityEventFactoryInterface $eventFactory;
```

- [ ] **Step 4: Replace all 4 `new EntityEvent()` calls**

Replace each `new EntityEvent($entity)` with `$this->eventFactory->create($entity)` at lines ~130, 191, 210, 233.

Remove the `use Waaseyaa\Entity\Event\EntityEvent;` import (no longer directly used).

- [ ] **Step 5: Run all tests**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All tests pass (5131+)

- [ ] **Step 6: Commit**

```bash
git add packages/entity-storage/src/SqlEntityStorage.php \
       packages/entity-storage/tests/Unit/SqlEntityStorageTest.php
git commit -m "refactor(#597): inject EntityEventFactory into SqlEntityStorage"
```

---

### Task 4: Inject factory into EntityRepository and replace calls

**Files:**
- Modify: `packages/entity-storage/src/EntityRepository.php` (lines 31-37, 98, 148, 155, 170, 183, 265, 269)
- Modify: `packages/entity-storage/tests/Unit/EntityRepositoryTest.php`

- [ ] **Step 1: Write a test verifying custom factory is called**

Add to `EntityRepositoryTest.php`:

```php
#[Test]
public function saveUsesInjectedEventFactory(): void
{
    $factoryCalled = false;
    $factory = new class($factoryCalled) implements EntityEventFactoryInterface {
        public function __construct(private bool &$called) {}
        public function create(EntityInterface $entity, ?EntityInterface $originalEntity = null): EntityEvent
        {
            $this->called = true;
            return new EntityEvent($entity, $originalEntity);
        }
    };

    $repository = new EntityRepository(
        $this->entityType,
        $this->driver,
        $this->eventDispatcher,
        eventFactory: $factory,
    );

    $entity = new TestStorageEntity(
        values: ['id' => '1', 'label' => 'Hello', 'bundle' => 'article', 'langcode' => 'en'],
        entityTypeId: 'test_entity',
        entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
    );
    $entity->enforceIsNew(true);

    $repository->save($entity);
    $this->assertTrue($factoryCalled, 'Custom event factory should be called during save');
}
```

Note: `EntityRepositoryTest` uses `#[Test]` attribute convention and `#[CoversClass]`. Add `use` imports for `EntityEventFactoryInterface`, `EntityEvent`, and `EntityInterface` at the top of the test file.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter "EntityRepositoryTest::saveUsesInjectedEventFactory"`
Expected: FAIL — unknown named parameter `eventFactory`

- [ ] **Step 3: Modify EntityRepository constructor**

```php
use Waaseyaa\Entity\Event\DefaultEntityEventFactory;
use Waaseyaa\Entity\Event\EntityEventFactoryInterface;

public function __construct(
    private readonly EntityTypeInterface $entityType,
    private readonly EntityStorageDriverInterface $driver,
    private readonly EventDispatcherInterface $eventDispatcher,
    private readonly ?RevisionableStorageDriver $revisionDriver = null,
    private readonly ?DatabaseInterface $database = null,
    ?EntityEventFactoryInterface $eventFactory = null,
) {
    $this->eventFactory = $eventFactory ?? new DefaultEntityEventFactory();
}

// Add property:
private readonly EntityEventFactoryInterface $eventFactory;
```

- [ ] **Step 4: Replace all 7 `new EntityEvent()` calls**

Replace each `new EntityEvent($entity)` with `$this->eventFactory->create($entity)` at lines ~98, 148, 155, 170, 183, 265, 269.

Remove the `use Waaseyaa\Entity\Event\EntityEvent;` import.

- [ ] **Step 5: Run all tests**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All tests pass (5131+)

- [ ] **Step 6: Commit**

```bash
git add packages/entity-storage/src/EntityRepository.php \
       packages/entity-storage/tests/Unit/EntityRepositoryTest.php
git commit -m "refactor(#597): inject EntityEventFactory into EntityRepository

Closes #597"
```

---

### Task 5: Final verification and cleanup

- [ ] **Step 1: Verify no direct `new EntityEvent()` remains in entity-storage**

Run: `grep -r 'new EntityEvent(' packages/entity-storage/src/`
Expected: No matches

- [ ] **Step 2: Run full test suite**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All tests pass

- [ ] **Step 3: Push**

```bash
git push origin main
```
