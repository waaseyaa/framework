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
    'vector_index' => [
        'node' => [
            'fields' => ['label', 'title', 'body', 'description'],
            'allow_external' => false,
        ],
    ],
],
```

Indexing is default-deny. Only declared entity types and fields are embedded.
`label` must be named explicitly; no label, entity ID, or other fallback text
is added. OpenAI and non-loopback Ollama endpoints may receive content only
when that entity type sets `allow_external` to `true`. Providers that do not
declare their egress behavior are treated as external. After removing a type
from the policy, run `semantic:refresh --type=<entity-type>` to delete its
existing vectors. That deletion-only refresh also works after the embedding
provider has been removed.

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

`EmbeddingStorageInterface` is the sole storage contract: atomic replacement
of one finite numeric vector per exact entity type/string ID, idempotent delete,
and `findSimilar()` arrays containing only `id` and cosine `score`. Results sort
by descending score, then bytewise ID; dimensions must match. Language variants
and stored metadata are unsupported. The old DTO/store family is removed; see
[UPGRADING.md](../../UPGRADING.md).

Missing schema refuses with `[AIV-STORAGE-001]`, corrupt stored vectors with
`[AIV-STORAGE-002]`. Warm/refresh counts only confirmed operations and propagates
failures. Post-commit listeners log failures and invalidate stale vectors.

The [semantic search contract](../../docs/specs/semantic-search-contract.md)
defines HTTP JSON:API and MCP identity, scores, ordering, current field-filtered
metadata, empty results and refusals. Shipped schemas live in this package's
`resources/` and `ai-tools/resources/`. The shared storage conformance suite
runs on real SQLite and hosted PostgreSQL; host stores must pass it too.

## Execution

HTTP lifecycle indexing uses one synchronous two-second network attempt after
true commit. CLI refresh and queries retain Ollama's 15-second and OpenAI's
20-second transfer budgets. Database and whole-request/batch time are separate.
Maintained Symfony HttpClient under `waaseyaa/http-client` owns HTTP mechanics;
no ext-curl requirement or embedding queue remains.

Source mutations transactionally advance durable generations and invalidate
vectors. Lifecycle indexing and refresh share guarded publication and fresh
served reads. Custom storage needs a compatible qualified execution guard;
custom HTTP-save providers need `EmbeddingSaveProviderInterface`.

The application operator schedules full `semantic:refresh` sweeps to its
freshness SLA and monitors listener errors, failed commands and search coverage.
Imports and failed embeddings can leave coverage absent until reconciliation.
Quiesce old workers and refresh when changing boot-time indexing policy. See
`docs/specs/semantic-search-contract.md` and FW-AIV-EXECUTION-01 for topology,
migration, custom-provider obligations and recovery details.

Invalidation is transactional and fail-closed for every kernel. Production has
no delayed post-delete/invalidate-only subscription; only configured HTTP
providers index after commit. Provider failure is best-effort after committed
save, while source invalidation failure rolls back the source mutation. Monitor
source errors and all indexing failures separately; AIV-EXECUTION-007
additionally identifies unconfirmed cleanup, not every provider failure.
