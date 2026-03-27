# Pass originalEntity to EntityEvent Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire `originalEntity` through all EntityRepository event dispatches so listeners can detect what changed.

**Architecture:** Load the persisted entity from storage before saving (for updates) and pass it as `originalEntity` to `EntityEventFactory::create()`. For deletes, the entity itself is the pre-delete state. No new classes or interfaces — the plumbing already exists but no call site uses it.

**Tech Stack:** PHP 8.4, PHPUnit 10.5, Symfony EventDispatcher

---

### Task 1: Enhance SpyEntityEventFactory to capture originalEntity

**Files:**
- Modify: `packages/entity-storage/tests/Fixtures/SpyEntityEventFactory.php`

- [ ] **Step 1: Update SpyEntityEventFactory to record calls with originalEntity**

Replace the entire file contents:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Fixtures;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEventFactoryInterface;

final class SpyEntityEventFactory implements EntityEventFactoryInterface
{
    public int $callCount = 0;

    /** @var list<array{entity: EntityInterface, originalEntity: ?EntityInterface}> */
    public array $calls = [];

    public function create(EntityInterface $entity, ?EntityInterface $originalEntity = null): EntityEvent
    {
        $this->callCount++;
        $this->calls[] = ['entity' => $entity, 'originalEntity' => $originalEntity];

        return new EntityEvent($entity, $originalEntity);
    }
}
```

- [ ] **Step 2: Run existing tests to confirm no regressions**

Run: `./vendor/bin/phpunit packages/entity-storage/tests/Unit/EntityRepositoryTest.php`
Expected: All 25 tests pass (the new `$calls` property doesn't break anything).

- [ ] **Step 3: Commit**

```bash
git add packages/entity-storage/tests/Fixtures/SpyEntityEventFactory.php
git commit -m "test(#642): enhance SpyEntityEventFactory to capture originalEntity"
```

---

### Task 2: Write failing tests for originalEntity in save events

**Files:**
- Modify: `packages/entity-storage/tests/Unit/EntityRepositoryTest.php`

- [ ] **Step 1: Write test — create passes null originalEntity**

Add this test method to `EntityRepositoryTest`:

```php
#[Test]
public function saveNewEntityPassesNullOriginalEntityToEvents(): void
{
    $events = [];

    $this->eventDispatcher->addListener(
        EntityEvents::PRE_SAVE->value,
        function (EntityEvent $event) use (&$events) {
            $events[] = ['event' => 'pre_save', 'originalEntity' => $event->originalEntity];
        },
    );

    $this->eventDispatcher->addListener(
        EntityEvents::POST_SAVE->value,
        function (EntityEvent $event) use (&$events) {
            $events[] = ['event' => 'post_save', 'originalEntity' => $event->originalEntity];
        },
    );

    $entity = new TestStorageEntity(
        values: ['id' => '1', 'label' => 'New', 'bundle' => 'article', 'langcode' => 'en'],
        entityTypeId: 'test_entity',
        entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
    );
    $entity->enforceIsNew(true);
    $this->repository->save($entity);

    $this->assertCount(2, $events);
    $this->assertNull($events[0]['originalEntity'], 'PRE_SAVE originalEntity should be null for new entities');
    $this->assertNull($events[1]['originalEntity'], 'POST_SAVE originalEntity should be null for new entities');
}
```

- [ ] **Step 2: Write test — update passes originalEntity with DB state**

Add this test method to `EntityRepositoryTest`:

```php
#[Test]
public function saveExistingEntityPassesOriginalEntityToEvents(): void
{
    $entity = new TestStorageEntity(
        values: ['id' => '1', 'label' => 'Original', 'bundle' => 'article', 'langcode' => 'en'],
        entityTypeId: 'test_entity',
        entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
    );
    $entity->enforceIsNew(true);
    $this->repository->save($entity);

    // Modify and save again
    $entity->set('label', 'Modified');

    $events = [];

    $this->eventDispatcher->addListener(
        EntityEvents::PRE_SAVE->value,
        function (EntityEvent $event) use (&$events) {
            $events[] = [
                'event' => 'pre_save',
                'label' => $event->entity->label(),
                'originalLabel' => $event->originalEntity?->label(),
            ];
        },
    );

    $this->eventDispatcher->addListener(
        EntityEvents::POST_SAVE->value,
        function (EntityEvent $event) use (&$events) {
            $events[] = [
                'event' => 'post_save',
                'label' => $event->entity->label(),
                'originalLabel' => $event->originalEntity?->label(),
            ];
        },
    );

    $this->repository->save($entity);

    $this->assertCount(2, $events);
    $this->assertSame('Modified', $events[0]['label']);
    $this->assertSame('Original', $events[0]['originalLabel'], 'PRE_SAVE should receive DB state as originalEntity');
    $this->assertSame('Modified', $events[1]['label']);
    $this->assertSame('Original', $events[1]['originalLabel'], 'POST_SAVE should receive DB state as originalEntity');
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `./vendor/bin/phpunit packages/entity-storage/tests/Unit/EntityRepositoryTest.php --filter "saveNewEntityPassesNullOriginalEntity|saveExistingEntityPassesOriginalEntity"`
Expected: `saveNewEntityPassesNullOriginalEntity` may pass (already null by default). `saveExistingEntityPassesOriginalEntity` MUST fail — `originalLabel` will be null because `originalEntity` isn't populated yet.

- [ ] **Step 4: Commit**

```bash
git add packages/entity-storage/tests/Unit/EntityRepositoryTest.php
git commit -m "test(#642): add failing tests for originalEntity in save events"
```

---

### Task 3: Write failing test for originalEntity in delete events

**Files:**
- Modify: `packages/entity-storage/tests/Unit/EntityRepositoryTest.php`

- [ ] **Step 1: Write test — delete passes entity as originalEntity**

Add this test method to `EntityRepositoryTest`:

```php
#[Test]
public function deletePassesEntityAsOriginalEntityToEvents(): void
{
    $entity = new TestStorageEntity(
        values: ['id' => '1', 'label' => 'ToDelete', 'bundle' => 'article', 'langcode' => 'en'],
        entityTypeId: 'test_entity',
        entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
    );
    $entity->enforceIsNew(true);
    $this->repository->save($entity);

    $events = [];

    $this->eventDispatcher->addListener(
        EntityEvents::PRE_DELETE->value,
        function (EntityEvent $event) use (&$events) {
            $events[] = [
                'event' => 'pre_delete',
                'originalEntity' => $event->originalEntity,
                'entity' => $event->entity,
            ];
        },
    );

    $this->eventDispatcher->addListener(
        EntityEvents::POST_DELETE->value,
        function (EntityEvent $event) use (&$events) {
            $events[] = [
                'event' => 'post_delete',
                'originalEntity' => $event->originalEntity,
                'entity' => $event->entity,
            ];
        },
    );

    $this->repository->delete($entity);

    $this->assertCount(2, $events);
    $this->assertSame($events[0]['entity'], $events[0]['originalEntity'], 'PRE_DELETE originalEntity should be the entity itself');
    $this->assertSame($events[1]['entity'], $events[1]['originalEntity'], 'POST_DELETE originalEntity should be the entity itself');
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/entity-storage/tests/Unit/EntityRepositoryTest.php --filter "deletePassesEntityAsOriginalEntity"`
Expected: FAIL — `originalEntity` is null, not the entity.

- [ ] **Step 3: Commit**

```bash
git add packages/entity-storage/tests/Unit/EntityRepositoryTest.php
git commit -m "test(#642): add failing test for originalEntity in delete events"
```

---

### Task 4: Implement originalEntity in doSave()

**Files:**
- Modify: `packages/entity-storage/src/EntityRepository.php:154-177` (doSave method)

- [ ] **Step 1: Add original entity loading at the start of doSave()**

In `EntityRepository::doSave()`, after line 157 (`$entityTypeId = ...`), add the original entity lookup:

```php
$originalEntity = null;
if (!$isNew) {
    $id = (string) $entity->id();
    $originalEntity = $this->find($id);
}
```

- [ ] **Step 2: Pass originalEntity to PRE_SAVE event factory call**

Change the PRE_SAVE dispatch (lines 173-177) from:

```php
$this->dispatchEvent(
    $this->eventFactory->create($entity),
    EntityEvents::PRE_SAVE->value,
    $unitOfWork,
);
```

to:

```php
$this->dispatchEvent(
    $this->eventFactory->create($entity, $originalEntity),
    EntityEvents::PRE_SAVE->value,
    $unitOfWork,
);
```

- [ ] **Step 3: Pass originalEntity to POST_SAVE event factory call**

Change the POST_SAVE dispatch (lines 215-219) from:

```php
$this->dispatchEvent(
    $this->eventFactory->create($entity),
    EntityEvents::POST_SAVE->value,
    $unitOfWork,
);
```

to:

```php
$this->dispatchEvent(
    $this->eventFactory->create($entity, $originalEntity),
    EntityEvents::POST_SAVE->value,
    $unitOfWork,
);
```

- [ ] **Step 4: Run save tests to verify they pass**

Run: `./vendor/bin/phpunit packages/entity-storage/tests/Unit/EntityRepositoryTest.php --filter "saveNewEntityPassesNullOriginalEntity|saveExistingEntityPassesOriginalEntity"`
Expected: Both PASS.

- [ ] **Step 5: Run full test suite to check for regressions**

Run: `./vendor/bin/phpunit packages/entity-storage/tests/Unit/EntityRepositoryTest.php`
Expected: All tests pass.

- [ ] **Step 6: Commit**

```bash
git add packages/entity-storage/src/EntityRepository.php
git commit -m "feat(#642): pass originalEntity to PRE_SAVE/POST_SAVE events"
```

---

### Task 5: Implement originalEntity in doDelete()

**Files:**
- Modify: `packages/entity-storage/src/EntityRepository.php:236-261` (doDelete method)

- [ ] **Step 1: Pass entity as originalEntity to PRE_DELETE event**

Change the PRE_DELETE dispatch (lines 245-249) from:

```php
$this->dispatchEvent(
    $this->eventFactory->create($entity),
    EntityEvents::PRE_DELETE->value,
    $unitOfWork,
);
```

to:

```php
$this->dispatchEvent(
    $this->eventFactory->create($entity, $entity),
    EntityEvents::PRE_DELETE->value,
    $unitOfWork,
);
```

- [ ] **Step 2: Pass entity as originalEntity to POST_DELETE event**

Change the POST_DELETE dispatch (lines 257-261) from:

```php
$this->dispatchEvent(
    $this->eventFactory->create($entity),
    EntityEvents::POST_DELETE->value,
    $unitOfWork,
);
```

to:

```php
$this->dispatchEvent(
    $this->eventFactory->create($entity, $entity),
    EntityEvents::POST_DELETE->value,
    $unitOfWork,
);
```

- [ ] **Step 3: Run delete test to verify it passes**

Run: `./vendor/bin/phpunit packages/entity-storage/tests/Unit/EntityRepositoryTest.php --filter "deletePassesEntityAsOriginalEntity"`
Expected: PASS.

- [ ] **Step 4: Run full test suite**

Run: `./vendor/bin/phpunit packages/entity-storage/tests/Unit/EntityRepositoryTest.php`
Expected: All tests pass.

- [ ] **Step 5: Run project-wide tests for regressions**

Run: `./vendor/bin/phpunit`
Expected: All tests pass. No listener code breaks because `originalEntity` was already `null` before — any listener accessing it was already guarding against null.

- [ ] **Step 6: Commit**

```bash
git add packages/entity-storage/src/EntityRepository.php
git commit -m "feat(#642): pass originalEntity to PRE_DELETE/POST_DELETE events"
```

---

### Task 6: Run code quality checks

**Files:** None (verification only)

- [ ] **Step 1: Run code style check**

Run: `composer cs-check`
Expected: No violations in modified files.

- [ ] **Step 2: Run static analysis**

Run: `composer phpstan`
Expected: No new errors. `find()` returns `?EntityInterface` which matches `$originalEntity`'s type.

- [ ] **Step 3: Fix any issues found, then commit**

If cs-check or phpstan reports issues, fix and commit:

```bash
composer cs-fix
git add -u
git commit -m "style(#642): fix code style"
```
