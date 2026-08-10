# Upgrade Note: Community-Scoped Translation Peer Storage

Issue #2322 moves two-axis translation peer persistence behind the optional `LangcodePeerStorageDriverV2Interface`. First-party SQL and in-memory V2 adapters implement it. Applications using those adapters receive the write-path fix automatically.

Custom V2 storage adapters used with `EntityRepository::saveTranslation()` must implement both capability methods:

```php
public function assertLangcodePeerMutationAllowed(
    string $entityType,
    string $id,
    string $langcode,
    string $defaultLangcode,
    StorageSnapshot $snapshot,
): void;

public function writeLangcodePeer(
    string $entityType,
    string $id,
    string $langcode,
    string $defaultLangcode,
    StorageSnapshot $snapshot,
): void;
```

The assertion runs before lifecycle events and must refuse an unauthorized exact peer or canonical base owner without mutation. The write owns the physical `(id, langcode)` upsert and repeats authorization as defense in depth. A tenant-aware implementation must authorize the canonical base row identified by `defaultLangcode`, stamp that owner onto new peers, and refuse foreign or unowned existing peers before mutation. The `StorageSnapshot` is opaque input and must not be replaced with entity introspection.

Historical peer rows with an empty `community_id` are not changed during boot or upgrade. Back up the database, run `php bin/waaseyaa tenancy:repair-translation-peers <entity_type> --dry-run --json`, review skipped rows, quiesce serving writes, then run the command without `--dry-run`. The repair only adopts deterministic peers with a non-empty canonical owner and, when UUID is keyed, an exact UUID match. See `docs/specs/operations-playbooks.md` for the complete procedure.

Unscoped entity types preserve their existing behavior. Storage adapters that never support two-axis translation writes do not need to implement the optional capability.
