# Batch Entity Operations Design

**Issue:** #577 — Add saveMany() and deleteMany() batch operations to EntityRepository
**Date:** 2026-03-23
**Status:** Design approved

## Problem

Every entity save/delete is a separate query with immediate event dispatch. Importing 10,000 articles means 10,000 INSERT statements and 20,000 event dispatches (PRE_SAVE + POST_SAVE each). No transaction wrapping, no batching.

## Design

### Interface Additions (`EntityRepositoryInterface`)

```php
/**
 * @param EntityInterface[] $entities
 * @return int[] Array of SAVED_NEW/SAVED_UPDATED per entity (same order as input).
 */
public function saveMany(array $entities, bool $validate = true): array;

/**
 * @param EntityInterface[] $entities
 * @return int Number of entities deleted.
 */
public function deleteMany(array $entities): int;
```

### Implementation in `EntityRepository`

Both methods create a `UnitOfWork` internally using `$this->database` and `$this->eventDispatcher`.

**`saveMany()`:**
1. Create `UnitOfWork` from `$this->database` + `$this->eventDispatcher`
2. Call `$unitOfWork->transaction(function () use ($entities, $validate) { ... })`
3. Inside the transaction, loop entities and call private `doSave($entity, $unitOfWork)`
4. `doSave()` contains the existing save logic but routes events through `$unitOfWork->bufferEvent()` instead of `$this->eventDispatcher->dispatch()`
5. Events dispatch after successful commit (UnitOfWork handles this)
6. On failure: transaction rolls back, events discarded, exception rethrows
7. Returns `int[]` of save results

**`deleteMany()`:**
1. Same UnitOfWork pattern
2. Inside transaction, loop entities and call private `doDelete($entity, $unitOfWork)`
3. Returns count of deleted entities

### Internal Refactor: `doSave()` and `doDelete()`

Extract the core logic from `save()` and `delete()` into private methods that accept an optional event dispatcher:

```php
private function doSave(EntityInterface $entity, ?UnitOfWork $unitOfWork = null): int
{
    // Existing save logic, but dispatch events via:
    $this->dispatchEvent($event, $eventName, $unitOfWork);
}

private function dispatchEvent(EntityEvent $event, string $eventName, ?UnitOfWork $unitOfWork = null): void
{
    if ($unitOfWork !== null) {
        $unitOfWork->bufferEvent($event, $eventName);
    } else {
        $this->eventDispatcher->dispatch($event, $eventName);
    }
}
```

Existing `save()` and `delete()` call `doSave()`/`doDelete()` with `$unitOfWork = null` (preserving current behavior).

### `validate` Parameter

The `bool $validate = true` parameter is a forward-looking hook for #569 (pre-save validation). For now, it's accepted but not acted upon — there's no validation logic yet. When #569 lands, `doSave()` will check this flag.

### Requirements

- `$this->database` must be non-null for batch operations (needed by UnitOfWork for transactions). If null, throw `\LogicException`.

## Test Strategy

- Unit test: `saveMany()` with mixed new/existing entities — verify correct SAVED_NEW/SAVED_UPDATED results
- Unit test: `saveMany()` dispatches events after commit (not during)
- Unit test: `deleteMany()` returns correct count
- Unit test: Transaction rollback on failure — no partial saves, no events dispatched
- Unit test: Empty array input returns empty result (no-op)

## Layer Compliance

All changes in `packages/entity-storage` (layer 1) and `packages/entity` (layer 1). No upward imports.
