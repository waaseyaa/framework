<?php

declare(strict_types=1);

// Migrated by bin/migrate-surface-map from docs/public-surface-map.php
// and docs/public-surface-map.md (FW-DELIVERY-SURFACE-01 / #2901). This
// file, not the generated docs/public-surface-map.*, is the editable
// authority — see docs/specs/public-surface-declarations.md.
return [
    'entries' => [
        ['fqcn' => 'Waaseyaa\\AI\\Vector\\EntityEmbeddingCleanupListener', 'disposition' => 'public', 'purpose' => 'Standalone current-absence cleanup with fresh source reads under a shared guard'],
        ['fqcn' => 'Waaseyaa\\AI\\Vector\\EmbeddingExecutionGuardInterface', 'disposition' => 'public', 'purpose' => 'Shared publication and transactional source-invalidation fence paired with storage'],
        ['fqcn' => 'Waaseyaa\\AI\\Vector\\DatabaseEmbeddingExecutionGuard', 'disposition' => 'public', 'purpose' => 'Durable same-database SQLite and PostgreSQL freshness fence'],
        ['fqcn' => 'Waaseyaa\\AI\\Vector\\EmbeddingSaveProviderInterface', 'disposition' => 'public', 'purpose' => 'Explicit bounded save-time provider operation'],
        ['fqcn' => 'Waaseyaa\\AI\\Vector\\EmbeddingExecutor', 'disposition' => 'internal', 'purpose' => 'Shared lifecycle and refresh policy execution and guarded cleanup'],
        ['fqcn' => 'Waaseyaa\\AI\\Vector\\EmbeddingSourceChangedListener', 'disposition' => 'internal', 'purpose' => 'Transaction-side source invalidation adapter'],
        ['fqcn' => 'Waaseyaa\\AI\\Vector\\EmbeddingHttpTransport', 'disposition' => 'internal', 'purpose' => 'Shared bounded HTTP mechanism for built-in embedding providers'],
        ['fqcn' => 'Waaseyaa\\AI\\Vector\\EmbeddingIndexPolicy', 'disposition' => 'public', 'purpose' => 'Default-deny entity-type, field-projection, and provider-egress policy for embedding generation'],
        ['fqcn' => 'Waaseyaa\\AI\\Vector\\EmbeddingInterface', 'disposition' => 'public', 'purpose' => 'Extends `EmbeddingProviderInterface` with batch embedding generation'],
        ['fqcn' => 'Waaseyaa\\AI\\Vector\\EmbeddingProviderEgressInterface', 'disposition' => 'public', 'purpose' => 'Declares whether an embedding provider may transmit source text off-host'],
        ['fqcn' => 'Waaseyaa\\AI\\Vector\\EmbeddingProviderInterface', 'disposition' => 'public', 'purpose' => 'Generates a vector embedding for a single text string'],
        ['fqcn' => 'Waaseyaa\\AI\\Vector\\EmbeddingStorageInterface', 'disposition' => 'public', 'purpose' => 'Stores and similarity-searches raw float vectors by entity type and ID'],
        ['fqcn' => 'Waaseyaa\\AI\\Vector\\InvalidEmbeddingIndexPolicyException', 'disposition' => 'public', 'purpose' => 'Stable refusal for malformed embedding index and egress configuration'],
        ['fqcn' => 'Waaseyaa\\AI\\Vector\\AiVectorServiceProvider', 'disposition' => 'public', 'purpose' => 'Opt-in composition of canonical storage, providers, policy and indexing lifecycle'],
        ['fqcn' => 'Waaseyaa\\AI\\Vector\\DatabaseEmbeddingStorage', 'disposition' => 'public', 'purpose' => 'Canonical storage on a migration-owned SQLite or PostgreSQL database'],
        ['fqcn' => 'Waaseyaa\\AI\\Vector\\EntityEmbeddingListener', 'disposition' => 'public', 'purpose' => 'Policy-enforced save and revision projection lifecycle'],
        ['fqcn' => 'Waaseyaa\\AI\\Vector\\OllamaEmbeddingProvider', 'disposition' => 'public', 'purpose' => 'Ollama embedding and egress implementation'],
        ['fqcn' => 'Waaseyaa\\AI\\Vector\\OpenAiEmbeddingProvider', 'disposition' => 'public', 'purpose' => 'OpenAI embedding and egress implementation'],
        ['fqcn' => 'Waaseyaa\\AI\\Vector\\ProviderCredentialConfigurationException', 'disposition' => 'public', 'purpose' => 'Non-sensitive provider credential refusal'],
        ['fqcn' => 'Waaseyaa\\AI\\Vector\\SearchController', 'disposition' => 'public', 'purpose' => 'JSON:API semantic_search v1.0 wire entry point'],
        ['fqcn' => 'Waaseyaa\\AI\\Vector\\SemanticIndexWarmer', 'disposition' => 'public', 'purpose' => 'Strict operator indexing and refresh reports using the shared policy'],
    ],
];
