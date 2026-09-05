<?php

declare(strict_types=1);

// Migrated by bin/migrate-surface-map from docs/public-surface-map.php
// and docs/public-surface-map.md (FW-DELIVERY-SURFACE-01 / #2901). This
// file, not the generated docs/public-surface-map.*, is the editable
// authority — see docs/specs/public-surface-declarations.md.
return [
    'entries' => [
        ['fqcn' => 'Waaseyaa\\AI\\Vector\\DistanceMetric', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\AI\\Vector\\EmbeddingInterface', 'disposition' => 'public', 'purpose' => 'Extends `EmbeddingProviderInterface` with batch embedding generation'],
        ['fqcn' => 'Waaseyaa\\AI\\Vector\\EmbeddingProviderInterface', 'disposition' => 'public', 'purpose' => 'Generates a vector embedding for a single text string'],
        ['fqcn' => 'Waaseyaa\\AI\\Vector\\EmbeddingStorageInterface', 'disposition' => 'public', 'purpose' => 'Stores and similarity-searches raw float vectors by entity type and ID'],
        ['fqcn' => 'Waaseyaa\\AI\\Vector\\VectorStoreInterface', 'disposition' => 'public', 'purpose' => 'Stores and queries entity embeddings in a vector backend (pgvector, Qdrant, etc.)'],
    ],
];
