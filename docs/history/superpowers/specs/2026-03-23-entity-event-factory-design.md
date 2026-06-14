# Entity Event Factory Extraction

**Issue:** #597 — Extract EntityEventFactory from SqlEntityStorage to decouple event creation
**Date:** 2026-03-23
**Status:** Design approved

## Problem

`SqlEntityStorage` and `EntityRepository` both call `new EntityEvent(...)` directly (4 and 7 calls respectively). This tightly couples storage to a concrete event class, making it impossible for applications to enrich entity events with contextual metadata (tenant ID, actor, correlation ID).

## Design

### Interface (in `packages/entity`)

```php
namespace Waaseyaa\Entity\Event;

interface EntityEventFactoryInterface
{
    public function create(
        EntityInterface $entity,
        ?EntityInterface $originalEntity = null,
    ): EntityEvent;
}
```

Lives in `packages/entity` (layer 1) because it references `EntityEvent` and `EntityInterface`, both owned by that package.

### Default Implementation (in `packages/entity`)

```php
namespace Waaseyaa\Entity\Event;

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

### Injection into Storage Classes (in `packages/entity-storage`)

Both `SqlEntityStorage` and `EntityRepository` receive the factory as an optional constructor parameter with a default:

```php
public function __construct(
    // ... existing params ...
    ?EntityEventFactoryInterface $eventFactory = null,
) {
    $this->eventFactory = $eventFactory ?? new DefaultEntityEventFactory();
}
```

### Replacement Pattern

All `new EntityEvent($entity)` calls become:

```php
$this->eventFactory->create($entity)
```

All `new EntityEvent($entity, $original)` calls become:

```php
$this->eventFactory->create($entity, $original)
```

## Scope

| File | `new EntityEvent` calls | Action |
|------|------------------------|--------|
| `SqlEntityStorage.php` | 4 | Replace with factory |
| `EntityRepository.php` | 7 | Replace with factory |

## Layer Compliance

- Interface + default impl in `packages/entity` (layer 1) — no upward imports
- Consumers in `packages/entity-storage` (layer 1) — same layer, valid import

## Test Strategy

- Existing tests continue to pass (default factory preserves behavior)
- Add unit test for `DefaultEntityEventFactory`
- Add test verifying custom factory injection in `SqlEntityStorage`
- Add test verifying custom factory injection in `EntityRepository`

## Extension Point for Applications

Applications like Minoo or Claudriel can provide a custom factory:

```php
final class TenantAwareEventFactory implements EntityEventFactoryInterface
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function create(
        EntityInterface $entity,
        ?EntityInterface $originalEntity = null,
    ): EntityEvent {
        // Enrich with tenant context, correlation ID, etc.
        return new EntityEvent($entity, $originalEntity);
    }
}
```
