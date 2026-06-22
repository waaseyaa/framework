# Design: Pass originalEntity to EntityEvent (#642)

## Problem

`EntityEventFactoryInterface::create()` accepts `?EntityInterface $originalEntity` but no call site in `EntityRepository` passes it — it's always `null`. Listeners cannot detect what changed during a save because they only see the post-mutation entity.

## Approach

**Load-before-save** (industry standard — matches Drupal, Doctrine, Rails):

- On **update**: `find($id)` before persisting, pass as `originalEntity`
- On **create**: `originalEntity` = `null` (nothing existed before)
- On **delete**: pass the entity itself as `originalEntity` (it is the pre-delete state)

One extra SELECT per update. Correct trade-off for reliable change detection.

## Changes

### `EntityRepository::doSave()`

1. After determining `$isNew`, load original if updating:
   ```php
   $originalEntity = null;
   if (!$isNew) {
       $id = (string) $entity->id();
       $originalEntity = $this->find($id);
   }
   ```
2. Pass `$originalEntity` to all `$this->eventFactory->create()` calls in `doSave()`:
   - PRE_SAVE: `$this->eventFactory->create($entity, $originalEntity)`
   - POST_SAVE: `$this->eventFactory->create($entity, $originalEntity)`
   - REVISION_CREATED: no change needed (no change-detection use case)

### `EntityRepository::doDelete()`

Pass entity as its own original for all delete events:
- PRE_DELETE: `$this->eventFactory->create($entity, $entity)`
- POST_DELETE: `$this->eventFactory->create($entity, $entity)`

### `saveMany()` / `deleteMany()`

No changes. `doSave()` handles its own `find()` per entity within the existing UnitOfWork transaction. Batch-load optimization deferred — YAGNI.

## What Does NOT Change

- `EntityEvent` class — already has `?EntityInterface $originalEntity` readonly property
- `EntityEventFactory` — already accepts and passes `$originalEntity`
- No new interfaces, no new classes

## Tests

Three new tests in `EntityRepositoryTest`:

1. **Update passes originalEntity**: Save entity, modify, save again. Assert `SpyEntityEventFactory` received the pre-modification entity as `originalEntity` for PRE_SAVE and POST_SAVE.
2. **Create passes null originalEntity**: Save new entity. Assert `originalEntity` is `null` for PRE_SAVE and POST_SAVE.
3. **Delete passes entity as originalEntity**: Save then delete. Assert `originalEntity` is the entity itself for PRE_DELETE and POST_DELETE.

## Semantic Summary

| Event | `$event->entity` | `$event->originalEntity` |
|---|---|---|
| PRE_SAVE (create) | new entity | `null` |
| POST_SAVE (create) | saved entity | `null` |
| PRE_SAVE (update) | modified entity | DB state before save |
| POST_SAVE (update) | saved entity | DB state before save |
| PRE_DELETE | entity being deleted | same entity |
| POST_DELETE | deleted entity | same entity |

## Listener Usage Pattern

```php
public function onPostSave(EntityEvent $event): void
{
    if ($event->originalEntity === null) {
        // This is a create — no prior state
        return;
    }

    // Change detection
    $old = $event->originalEntity->get('status');
    $new = $event->entity->get('status');
    if ($old !== $new) {
        // Status changed from $old to $new
    }
}
```
