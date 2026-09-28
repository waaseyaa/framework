# waaseyaa/ai-vector

**Layer 5 — AI**

Opt-in vector embedding storage and similarity search for Waaseyaa applications.

Installing the package does not activate it. Enable it explicitly in application
config, after running its migration:

```php
'ai' => [
    'vector_enabled' => true,
    'vector_backend' => 'database',
    'embedding_provider' => 'ollama', // or openai
],
```

`database` is the only supported storage backend. It stores JSON vectors on
the application's `DatabaseInterface` connection and performs cosine ranking
in PHP. Selecting `pgvector` or any other backend fails startup with
`[AIV-BACKEND-001]`; the package does not silently substitute another backend.

The `semantic:warm` and `semantic:refresh` CLI commands appear only when the
package is installed and `ai.vector_enabled` is true. Without ai-vector, the
semantic search route returns 501. With the package installed but disabled, it
binds no services, registers no lifecycle listeners, and exposes no semantic
commands.

Key production contracts: `EmbeddingStorageInterface`,
`EmbeddingProviderInterface`, `DatabaseEmbeddingStorage`, and
`SemanticIndexWarmer`. `FakeEmbeddingProvider` is available only through the
package's development autoloader.
