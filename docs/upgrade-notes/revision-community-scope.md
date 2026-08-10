# Upgrade Note: Community-Scoped Revision Storage

Issue #2320 makes revision history follow the same community boundary as its canonical base entity row. Existing applications receive the fix automatically when the kernel builds repositories; no database migration or revision-table backfill is required.

Applications that directly implement the public `RevisionableStorageDriverV2Interface` must add two methods:

```php
public function assertEntityMutationAllowed(string $entityId): void;
public function requiresBaseAnchor(): bool;
```

The first must refuse a revision mutation unless the entity is visible in the active tenant context. The second returns `true` when revision 1 must be deferred until a tenant-owned base row exists. Implementors that do not support tenant scoping may preserve prior behavior by making the assertion a no-op and returning `false`.

Direct users of the first-party `RevisionableStorageDriver` need no changes. Its optional `CommunityScope` constructor argument defaults to `null`, preserving unscoped behavior.
