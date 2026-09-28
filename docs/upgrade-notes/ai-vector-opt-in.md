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
],
```

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
