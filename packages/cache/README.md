# waaseyaa/cache

**Layer 0 — Foundation**

Cache bins with cache-tag invalidation for Waaseyaa applications.

Provides a backend-agnostic `CacheBackendInterface` (plus the tag-aware
`TaggedCacheInterface` / `TagAwareCacheInterface`) and three backends:

- **`MemoryBackend`** — in-process store with a precise dual tag-index; ideal for tests.
- **`DatabaseBackend`** — PDO-backed persistent store (the production backend). Serializes
  values into a per-bin table; corrupt/unreadable rows are treated as a cache miss, and an
  optional HMAC integrity key (off by default) makes tampering detectable. There is **no**
  filesystem backend.
- **`NullBackend`** — no-op store.

`CacheFactory` / `CacheConfiguration` resolve a backend per *bin*. Cache-tag invalidation runs
through `CacheTagsInvalidator`; the event listeners (`EntityCacheInvalidator`,
`ConfigCacheInvalidator`, `TranslationCacheInvalidator`, `EntityCacheSubscriber`) invalidate
tagged entries on entity/config/translation lifecycle events. `CacheConfigResolver` computes
cache-control headers, and `ContextResolver` / `ContextRegistry` resolve cache contexts.

Key classes: `CacheBackendInterface`, `TaggedCacheInterface`, `CacheItem`, `CacheFactory`,
`CacheConfiguration`, `MemoryBackend`, `DatabaseBackend`, `NullBackend`, `CacheTagsInvalidator`,
`CacheConfigResolver`.
