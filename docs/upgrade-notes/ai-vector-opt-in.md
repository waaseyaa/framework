# Upgrade Note: AI Vector Is Explicitly Opt-In

`waaseyaa/ai-vector` is no longer installed by `waaseyaa/framework`,
`waaseyaa/cli`, or `waaseyaa/full`. Applications that use semantic embeddings
must require it directly and activate it explicitly.

```bash
composer require waaseyaa/ai-vector
php vendor/bin/waaseyaa migrate
```

```php
'ai' => [
    'vector_enabled' => true,
    'vector_backend' => 'database',
    // Keep or add the existing embedding_provider configuration.
    'vector_index' => [
        'node' => [
            'fields' => ['label', 'title', 'body', 'description'],
            'allow_external' => false,
        ],
    ],
],
```

`ai.vector_index` is required for content to be embedded. The old skeleton
`ai.embedding_fields` placeholder was never consumed and is replaced by this
enforced policy. Undeclared entity types are removed from the index when they
are saved or explicitly refreshed. After removing a previously indexed type,
run `semantic:refresh --type=<entity-type>` to remove its stored vectors. The
deletion-only refresh works even when no embedding provider remains configured.

`allow_external` defaults to `false`. Set it to `true` only for entity types
whose declared fields may be sent to OpenAI, a non-loopback Ollama endpoint,
or an embedding provider whose egress behavior is unknown.

The skeleton also supports `WAASEYAA_AI_VECTOR_ENABLED=true` and
`WAASEYAA_AI_VECTOR_BACKEND=database`.

An installed package with no explicit enablement has no vector bindings,
lifecycle listeners, secret-consumer registration, or semantic CLI commands.
An application without the package keeps the existing 501 response for
semantic search. `semantic:warm` and `semantic:refresh` are advertised only
when the package is both present and enabled.

The former `sqlite` and `pgvector` sovereignty labels were claims the runtime
did not implement. The supported backend is now named `database`: JSON vectors
are stored through the application's database connection and ranked in PHP.
Set `vector_backend` to `database`. Selecting `pgvector`, `sqlite`, or an
unknown value now fails startup with `[AIV-BACKEND-001]` instead of silently
using the portable database implementation.

`Waaseyaa\AI\Vector\Testing\FakeEmbeddingProvider` moved from production
autoload to `autoload-dev`. Production consumers must not use it. Test suites
that need it must add an explicit development autoload mapping because Composer
does not load a dependency's `autoload-dev` rules:

```json
{
    "autoload-dev": {
        "psr-4": {
            "Waaseyaa\\AI\\Vector\\Testing\\": "vendor/waaseyaa/ai-vector/testing/"
        }
    }
}
```

The public `SemanticWarmHandler` and `SemanticRefreshHandler` constructors now
accept deferred `Closure` callbacks. Applications that instantiate either
handler directly must provide the corresponding warming callback; normal CLI
composition supplies it automatically.
