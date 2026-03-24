# Batch Entity Operations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `saveMany()` and `deleteMany()` batch operations to `EntityRepository` with transaction wrapping and post-commit event dispatch.

**Architecture:** Extract `save()` and `delete()` internals into private `doSave()`/`doDelete()` methods that accept an optional `UnitOfWork` for event routing. Batch methods create a `UnitOfWork` internally and delegate to these private methods. Events buffer during the transaction and dispatch after commit.

**Tech Stack:** PHP 8.4, PHPUnit 10.5, UnitOfWork (existing), EntityEventFactoryInterface

**Spec:** `docs/superpowers/specs/2026-03-23-batch-entity-operations-design.md`

---

### File Map

| Action | File | Responsibility |
|--------|------|---------------|
| Modify | `packages/entity/src/Repository/EntityRepositoryInterface.php` | Add `saveMany()` and `deleteMany()` to contract |
| Modify | `packages/entity-storage/src/EntityRepository.php` | Implement batch methods, extract `doSave()`/`doDelete()`/`dispatchEvent()` |
| Modify | `packages/entity-storage/tests/Unit/EntityRepositoryTest.php` | Add batch operation tests |

---

### Task 1: Add saveMany()/deleteMany() to EntityRepositoryInterface

**Files:**
- Modify: `packages/entity/src/Repository/EntityRepositoryInterface.php`

- [ ] **Step 1: Add method signatures to the interface**

Add before the closing `}`:

```php
/**
 * Save multiple entities in a single transaction.
 *
 * Events are buffered during the transaction and dispatched after commit.
 * On failure, all changes are rolled back and no events are dispatched.
 *
 * @param EntityInterface[] $entities The entities to save.
 * @param bool $validate Whether to run pre-save validation (forward-looking hook for #569).
 * @return int[] Array of SAVED_NEW/SAVED_UPDATED per entity (same order as input).
 * @throws \LogicException If no database connection is available for transactions.
 */
public function saveMany(array $entities, bool $validate = true): array;

/**
 * Delete multiple entities in a single transaction.
 *
 * Events are buffered during the transaction and dispatched after commit.
 * On failure, all changes are rolled back and no events are dispatched.
 *
 * @param EntityInterface[] $entities The entities to delete.
 * @return int Number of entities deleted.
 * @throws \LogicException If no database connection is available for transactions.
 */
public function deleteMany(array $entities): int;
```

- [ ] **Step 2: Run tests to verify nothing breaks**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All tests pass (existing code doesn't call these methods yet)

- [ ] **Step 3: Commit**

```bash
git add packages/entity/src/Repository/EntityRepositoryInterface.php
git commit -m "feat(#577): add saveMany()/deleteMany() to EntityRepositoryInterface"
```

---

### Task 2: Extract dispatchEvent() helper in EntityRepository

**Files:**
- Modify: `packages/entity-storage/src/EntityRepository.php`

- [ ] **Step 1: Add the UnitOfWork import and dispatchEvent() method**

Add import:
```php
use Waaseyaa\EntityStorage\UnitOfWork;
```

Add private method at the end of the class (before closing `}`):

```php
private function dispatchEvent(object $event, string $eventName, ?UnitOfWork $unitOfWork = null): void
{
    if ($unitOfWork !== null) {
        $unitOfWork->bufferEvent($event, $eventName);
    } else {
        $this->eventDispatcher->dispatch($event, $eventName);
    }
}
```

- [ ] **Step 2: Replace all `$this->eventDispatcher->dispatch(...)` calls in save() and delete() with dispatchEvent()**

In `save()` (3 dispatch calls):
```php
// PRE_SAVE (line ~103)
$this->dispatchEvent(
    $this->eventFactory->create($entity),
    EntityEvents::PRE_SAVE->value,
);

// POST_SAVE (line ~153)
$this->dispatchEvent(
    $this->eventFactory->create($entity),
    EntityEvents::POST_SAVE->value,
);

// REVISION_CREATED (line ~160)
$this->dispatchEvent(
    $this->eventFactory->create($entity),
    EntityEvents::REVISION_CREATED->value,
);
```

In `delete()` (2 dispatch calls):
```php
// PRE_DELETE
$this->dispatchEvent(
    $this->eventFactory->create($entity),
    EntityEvents::PRE_DELETE->value,
);

// POST_DELETE
$this->dispatchEvent(
    $this->eventFactory->create($entity),
    EntityEvents::POST_DELETE->value,
);
```

Also replace the 2 dispatch calls in `rollback()` (REVISION_CREATED + REVISION_REVERTED).

- [ ] **Step 3: Run tests to verify refactor is behavior-preserving**

Run: `./vendor/bin/phpunit --filter EntityRepositoryTest`
Expected: All existing tests pass (no behavior change — `$unitOfWork` is null, so events dispatch immediately as before)

- [ ] **Step 4: Commit**

```bash
git add packages/entity-storage/src/EntityRepository.php
git commit -m "refactor(#577): extract dispatchEvent() helper in EntityRepository"
```

---

### Task 3: Extract doSave() and doDelete() private methods

**Files:**
- Modify: `packages/entity-storage/src/EntityRepository.php`

- [ ] **Step 1: Extract doSave()**

Create private method containing the core save logic from `save()`, but accepting an optional `UnitOfWork`:

```php
private function doSave(EntityInterface $entity, ?UnitOfWork $unitOfWork = null): int
{
    $isNew = $entity->isNew();
    $entityTypeId = $this->entityType->id();

    $this->dispatchEvent(
        $this->eventFactory->create($entity),
        EntityEvents::PRE_SAVE->value,
        $unitOfWork,
    );

    $values = $entity->toArray();
    $id = (string) ($entity->id() ?? '');
    $createRevision = $this->shouldCreateRevision($entity, $isNew);

    // Wrap revision + base table writes in a transaction (invariant #4).
    // Skip if already inside a UnitOfWork transaction.
    $transaction = ($unitOfWork === null) ? $this->database?->transaction() : null;
    try {
        if ($createRevision && $this->revisionDriver !== null) {
            $log = ($entity instanceof RevisionableInterface) ? $entity->getRevisionLog() : null;
            $revisionId = $this->revisionDriver->writeRevision($id, $values, $log);
            $values['revision_id'] = $revisionId;
            if ($entity instanceof ContentEntityInterface) {
                $revisionKey = $this->entityType->getKeys()['revision'] ?? 'revision_id';
                $entity->set($revisionKey, $revisionId);
            }
        } elseif (!$createRevision && !$isNew && $this->revisionDriver !== null && $entity instanceof RevisionableInterface) {
            $currentRevisionId = $entity->getRevisionId();
            if ($currentRevisionId !== null) {
                $this->revisionDriver->updateRevision($id, $currentRevisionId, $values);
            }
        }

        $this->driver->write($entityTypeId, $id, $values);
        $transaction?->commit();
    } catch (\Throwable $e) {
        $transaction?->rollBack();
        throw $e;
    }

    if ($isNew && method_exists($entity, 'enforceIsNew')) {
        $entity->enforceIsNew(false);
    }

    $result = $isNew ? EntityConstants::SAVED_NEW : EntityConstants::SAVED_UPDATED;

    $this->dispatchEvent(
        $this->eventFactory->create($entity),
        EntityEvents::POST_SAVE->value,
        $unitOfWork,
    );

    if ($createRevision && $this->revisionDriver !== null) {
        $this->dispatchEvent(
            $this->eventFactory->create($entity),
            EntityEvents::REVISION_CREATED->value,
            $unitOfWork,
        );
    }

    return $result;
}
```

Simplify `save()` to delegate:

```php
public function save(EntityInterface $entity): int
{
    return $this->doSave($entity);
}
```

- [ ] **Step 2: Extract doDelete()**

```php
private function doDelete(EntityInterface $entity, ?UnitOfWork $unitOfWork = null): void
{
    $entityTypeId = $this->entityType->id();
    $id = (string) $entity->id();

    $this->dispatchEvent(
        $this->eventFactory->create($entity),
        EntityEvents::PRE_DELETE->value,
        $unitOfWork,
    );

    if ($this->revisionDriver !== null && $this->entityType->isRevisionable()) {
        $this->revisionDriver->deleteAllRevisions($id);
    }

    $this->driver->remove($entityTypeId, $id);

    $this->dispatchEvent(
        $this->eventFactory->create($entity),
        EntityEvents::POST_DELETE->value,
        $unitOfWork,
    );
}
```

Simplify `delete()` to delegate:

```php
public function delete(EntityInterface $entity): void
{
    $this->doDelete($entity);
}
```

- [ ] **Step 3: Run tests to verify refactor is behavior-preserving**

Run: `./vendor/bin/phpunit --filter EntityRepositoryTest`
Expected: All existing tests pass

- [ ] **Step 4: Commit**

```bash
git add packages/entity-storage/src/EntityRepository.php
git commit -m "refactor(#577): extract doSave()/doDelete() private methods in EntityRepository"
```

---

### Task 4: Implement saveMany() with TDD

**Files:**
- Modify: `packages/entity-storage/src/EntityRepository.php`
- Modify: `packages/entity-storage/tests/Unit/EntityRepositoryTest.php`

- [ ] **Step 1: Write failing tests for saveMany()**

Add to `EntityRepositoryTest.php`:

```php
#[Test]
public function saveManyReturnsResultsPerEntity(): void
{
    $entity1 = new TestStorageEntity(
        values: ['id' => '1', 'label' => 'First', 'bundle' => 'article', 'langcode' => 'en'],
        entityTypeId: 'test_entity',
        entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
    );
    $entity1->enforceIsNew(true);

    $entity2 = new TestStorageEntity(
        values: ['id' => '2', 'label' => 'Second', 'bundle' => 'article', 'langcode' => 'en'],
        entityTypeId: 'test_entity',
        entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
    );
    $entity2->enforceIsNew(true);

    $db = DBALDatabase::createSqlite();
    $driver = new SqlStorageDriver(new SingleConnectionResolver($db));
    (new \Waaseyaa\EntityStorage\SqlSchemaHandler($this->entityType, $db))->ensureTable();

    $repository = new EntityRepository(
        $this->entityType,
        $driver,
        $this->eventDispatcher,
        database: $db,
    );

    $results = $repository->saveMany([$entity1, $entity2]);

    $this->assertCount(2, $results);
    $this->assertSame(EntityConstants::SAVED_NEW, $results[0]);
    $this->assertSame(EntityConstants::SAVED_NEW, $results[1]);
}

#[Test]
public function saveManyWithEmptyArrayReturnsEmpty(): void
{
    $db = DBALDatabase::createSqlite();
    $driver = new SqlStorageDriver(new SingleConnectionResolver($db));

    $repository = new EntityRepository(
        $this->entityType,
        $driver,
        $this->eventDispatcher,
        database: $db,
    );

    $results = $repository->saveMany([]);
    $this->assertSame([], $results);
}

#[Test]
public function saveManyDispatchesEventsAfterCommit(): void
{
    $entity = new TestStorageEntity(
        values: ['id' => '1', 'label' => 'Test', 'bundle' => 'article', 'langcode' => 'en'],
        entityTypeId: 'test_entity',
        entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
    );
    $entity->enforceIsNew(true);

    $db = DBALDatabase::createSqlite();
    $driver = new SqlStorageDriver(new SingleConnectionResolver($db));
    (new \Waaseyaa\EntityStorage\SqlSchemaHandler($this->entityType, $db))->ensureTable();

    $events = [];
    $this->eventDispatcher->addListener(EntityEvents::PRE_SAVE->value, function () use (&$events) {
        $events[] = 'pre_save';
    });
    $this->eventDispatcher->addListener(EntityEvents::POST_SAVE->value, function () use (&$events) {
        $events[] = 'post_save';
    });

    $repository = new EntityRepository(
        $this->entityType,
        $driver,
        $this->eventDispatcher,
        database: $db,
    );

    $repository->saveMany([$entity]);

    $this->assertSame(['pre_save', 'post_save'], $events);
}

#[Test]
public function saveManyThrowsWithoutDatabase(): void
{
    $this->expectException(\LogicException::class);
    $this->repository->saveMany([
        new TestStorageEntity(
            values: ['id' => '1', 'label' => 'Test', 'bundle' => 'article', 'langcode' => 'en'],
            entityTypeId: 'test_entity',
            entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
        ),
    ]);
}
```

Add imports at top of test file:
```php
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit --filter "EntityRepositoryTest::saveManyReturnsResultsPerEntity"`
Expected: FAIL — method `saveMany` not found

- [ ] **Step 3: Implement saveMany()**

```php
public function saveMany(array $entities, bool $validate = true): array
{
    if ($entities === []) {
        return [];
    }

    if ($this->database === null) {
        throw new \LogicException('saveMany() requires a database connection for transaction support.');
    }

    $unitOfWork = new UnitOfWork($this->database, $this->eventDispatcher);
    $results = [];

    return $unitOfWork->transaction(function () use ($entities, &$results, $unitOfWork): array {
        foreach ($entities as $entity) {
            $results[] = $this->doSave($entity, $unitOfWork);
        }

        return $results;
    });
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit --filter "saveManyReturnsResultsPerEntity|saveManyWithEmptyArray|saveManyDispatchesEvents|saveManyThrowsWithoutDatabase"`
Expected: All 4 pass

- [ ] **Step 5: Commit**

```bash
git add packages/entity-storage/src/EntityRepository.php \
       packages/entity-storage/tests/Unit/EntityRepositoryTest.php
git commit -m "feat(#577): implement saveMany() with transaction and buffered events"
```

---

### Task 5: Implement deleteMany() with TDD

**Files:**
- Modify: `packages/entity-storage/src/EntityRepository.php`
- Modify: `packages/entity-storage/tests/Unit/EntityRepositoryTest.php`

- [ ] **Step 1: Write failing tests for deleteMany()**

```php
#[Test]
public function deleteManyReturnsCount(): void
{
    $db = DBALDatabase::createSqlite();
    $driver = new SqlStorageDriver(new SingleConnectionResolver($db));
    (new \Waaseyaa\EntityStorage\SqlSchemaHandler($this->entityType, $db))->ensureTable();

    $repository = new EntityRepository(
        $this->entityType,
        $driver,
        $this->eventDispatcher,
        database: $db,
    );

    $entity1 = new TestStorageEntity(
        values: ['id' => '1', 'label' => 'First', 'bundle' => 'article', 'langcode' => 'en'],
        entityTypeId: 'test_entity',
        entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
    );
    $entity1->enforceIsNew(true);

    $entity2 = new TestStorageEntity(
        values: ['id' => '2', 'label' => 'Second', 'bundle' => 'article', 'langcode' => 'en'],
        entityTypeId: 'test_entity',
        entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
    );
    $entity2->enforceIsNew(true);

    $repository->save($entity1);
    $repository->save($entity2);

    $count = $repository->deleteMany([$entity1, $entity2]);
    $this->assertSame(2, $count);
    $this->assertNull($repository->find('1'));
    $this->assertNull($repository->find('2'));
}

#[Test]
public function deleteManyWithEmptyArrayReturnsZero(): void
{
    $db = DBALDatabase::createSqlite();
    $driver = new SqlStorageDriver(new SingleConnectionResolver($db));

    $repository = new EntityRepository(
        $this->entityType,
        $driver,
        $this->eventDispatcher,
        database: $db,
    );

    $this->assertSame(0, $repository->deleteMany([]));
}

#[Test]
public function deleteManyThrowsWithoutDatabase(): void
{
    $this->expectException(\LogicException::class);
    $entity = new TestStorageEntity(
        values: ['id' => '1', 'label' => 'Test', 'bundle' => 'article', 'langcode' => 'en'],
        entityTypeId: 'test_entity',
        entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
    );
    $this->repository->deleteMany([$entity]);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit --filter "deleteManyReturnsCount"`
Expected: FAIL — method `deleteMany` not found

- [ ] **Step 3: Implement deleteMany()**

```php
public function deleteMany(array $entities): int
{
    if ($entities === []) {
        return 0;
    }

    if ($this->database === null) {
        throw new \LogicException('deleteMany() requires a database connection for transaction support.');
    }

    $unitOfWork = new UnitOfWork($this->database, $this->eventDispatcher);

    return $unitOfWork->transaction(function () use ($entities, $unitOfWork): int {
        foreach ($entities as $entity) {
            $this->doDelete($entity, $unitOfWork);
        }

        return count($entities);
    });
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit --filter "deleteManyReturnsCount|deleteManyWithEmptyArray|deleteManyThrowsWithoutDatabase"`
Expected: All 3 pass

- [ ] **Step 5: Commit**

```bash
git add packages/entity-storage/src/EntityRepository.php \
       packages/entity-storage/tests/Unit/EntityRepositoryTest.php
git commit -m "feat(#577): implement deleteMany() with transaction and buffered events

Closes #577"
```

---

### Task 6: Final verification and push

- [ ] **Step 1: Run full test suite**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All tests pass (5135+)

- [ ] **Step 2: Push**

```bash
git push origin main
```
