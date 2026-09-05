<?php

declare(strict_types=1);

// Migrated by bin/migrate-surface-map from docs/public-surface-map.php
// and docs/public-surface-map.md (FW-DELIVERY-SURFACE-01 / #2901). This
// file, not the generated docs/public-surface-map.*, is the editable
// authority — see docs/specs/public-surface-declarations.md.
return [
    'entries' => [
        ['fqcn' => 'Waaseyaa\\Cache\\CacheBackendInterface', 'disposition' => 'public', 'purpose' => 'Reads and writes cache items with optional tag and expiry support'],
        ['fqcn' => 'Waaseyaa\\Cache\\CacheFactoryInterface', 'disposition' => 'public', 'purpose' => 'Creates or retrieves cache backend instances by bin name'],
        ['fqcn' => 'Waaseyaa\\Cache\\CacheTagsInvalidatorInterface', 'disposition' => 'public', 'purpose' => 'Invalidates all cache items associated with a set of tags'],
        ['fqcn' => 'Waaseyaa\\Cache\\ContextNames', 'disposition' => 'public', 'purpose' => 'Canonical context-name constants (`USER_ROLES`, `USER_ID`, `LANGUAGE_CONTENT`, `LANGUAGE_INTERFACE`, `URL_QUERY_PREFIX`)'],
        ['fqcn' => 'Waaseyaa\\Cache\\ContextRegistry', 'disposition' => 'public', 'purpose' => 'Whitelist of canonical context names for cache-key segmentation (charter §5.9)'],
        ['fqcn' => 'Waaseyaa\\Cache\\ContextResolver', 'disposition' => 'public', 'purpose' => 'Resolves a context name against a `RequestContext` into a deterministic short string'],
        ['fqcn' => 'Waaseyaa\\Cache\\EntityPayloadBoundaryConfig', 'disposition' => 'public', 'purpose' => 'Explicit dormant/enforced cache entity-payload write mode'],
        ['fqcn' => 'Waaseyaa\\Cache\\Exception\\EntityProjectionWriteForbidden', 'disposition' => 'public', 'purpose' => 'Activated cache rejection before an entity graph can be retained'],
        ['fqcn' => 'Waaseyaa\\Cache\\Exception\\InvalidCacheTagException', 'disposition' => 'public', 'purpose' => 'Thrown by `setWithTags()` on malformed tag strings (no silent normalisation)'],
        ['fqcn' => 'Waaseyaa\\Cache\\ProjectionDeprecationDiagnostic', 'disposition' => 'public', 'purpose' => 'Deduplicated dormant entity-payload diagnostic wired into first-party cache writes'],
        ['fqcn' => 'Waaseyaa\\Cache\\ProtectedCacheDimensions', 'disposition' => 'public', 'purpose' => 'Complete protected-cache authority, bundle, language, revision, and generation key dimensions'],
        ['fqcn' => 'Waaseyaa\\Cache\\TagAwareCacheInterface', 'disposition' => 'public', 'purpose' => 'Cache backend that supports tag-based invalidation'],
        ['fqcn' => 'Waaseyaa\\Cache\\TaggedCacheInterface', 'disposition' => 'public', 'purpose' => 'Listing-pipeline tag-aware ops (`setWithTags`, `invalidateByTag`, `getTagsFor`) — charter §5.9'],
    ],
];
