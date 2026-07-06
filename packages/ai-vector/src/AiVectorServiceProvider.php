<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector;

use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Workflows\WorkflowVisibility;

/**
 * Binds `waaseyaa/ai-vector`'s interfaces so the kernel container can
 * autowire consumers (notably `SemanticIndexWarmer`, used by the
 * `semantic:warm` and `semantic:refresh` CLI handlers).
 *
 * Before this provider existed, `packages/ai-vector` shipped no service
 * provider and its `composer.json` `extra` carried no `waaseyaa` key, so
 * `EmbeddingStorageInterface` had no binding: `KernelHandlerContainer`
 * autowiring `SemanticIndexWarmer` hit the interface parameter and threw
 * `No binding for "Waaseyaa\AI\Vector\EmbeddingStorageInterface"`.
 *
 * `EmbeddingProviderInterface` is bound only when one is configured:
 * `EmbeddingProviderFactory::fromConfig()` returns null when no
 * `ai.embedding_provider` is set, and `ServiceProvider::resolve()` requires
 * a bound concrete to produce an object, so an unconfigured install leaves
 * the interface unbound (and `resolveOptional()` yields null). When it IS
 * configured, the binding both feeds `SemanticIndexWarmer` and satisfies the
 * `vector.search` tool's embedding-provider resolver closure (the tool lives
 * in `waaseyaa/ai-tools`, which duck-types these interfaces by design; a host
 * wires the tool's `\Closure` resolvers, and they now resolve real ai-vector
 * services off the kernel-services bus because both interfaces are bound
 * here). When it is NOT configured, `SemanticIndexWarmer` receives a null
 * provider and reports `skipped_no_provider`, the correct graceful degrade.
 *
 * @api
 */
final class AiVectorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton(
            EmbeddingStorageInterface::class,
            fn(): EmbeddingStorageInterface => new SqliteEmbeddingStorage(
                $this->resolve(\PDO::class),
                'embeddings',
                $this->resolveOptional(LoggerInterface::class),
            ),
        );

        // Resolve the configured provider once. fromConfig() only constructs a
        // value object from config (no I/O), returning null when unconfigured.
        $configuredProvider = EmbeddingProviderFactory::fromConfig($this->config);
        if ($configuredProvider !== null) {
            $this->singleton(
                EmbeddingProviderInterface::class,
                fn(): EmbeddingProviderInterface => $configuredProvider,
            );
        }

        // Bound directly (not autowired by KernelHandlerContainer): the
        // nullable `?EmbeddingProviderInterface` constructor parameter cannot
        // be resolved by reflection-based autowiring.
        $this->singleton(
            SemanticIndexWarmer::class,
            fn(): SemanticIndexWarmer => new SemanticIndexWarmer(
                $this->resolve(EntityTypeManagerInterface::class),
                $this->resolve(EmbeddingStorageInterface::class),
                $configuredProvider,
                new WorkflowVisibility(),
            ),
        );
    }
}
