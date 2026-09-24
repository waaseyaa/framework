<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\EntityStorage\Event\RevisionPointerMovedEvent;
use Waaseyaa\Foundation\Event\EventDispatcherInterface;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\Security\SecretResolverRegistry;
use Waaseyaa\Foundation\ServiceProvider\Capability\ConfiguresHttpKernelInterface;
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
 * It is also the only composition owner for the lifecycle listeners
 * (FW-AIV-COMP-01): they use exactly the storage and provider bound here,
 * the same instances search, the warmer and bus consumers get.
 *
 * - Every kernel (CLI, imports, workers): `boot()` registers vector removal
 *   on delete and an `invalidateOnly` save listener, which removes any
 *   existing vector and never calls the embedding provider;
 *   `semantic:refresh` re-indexes.
 * - HTTP with a configured provider: `configureHttpKernel()` replaces the
 *   invalidating save listener with the embedding one.
 *
 * @api
 */
final class AiVectorServiceProvider extends ServiceProvider implements ConfiguresHttpKernelInterface
{
    private ?EventDispatcherInterface $lifecycleDispatcher = null;
    private ?EntityEmbeddingListener $saveListener = null;
    private bool $embeddingOnSave = false;

    public function register(): void
    {
        $secretRegistry = $this->kernelServices?->get(SecretResolverRegistry::class);
        if ($secretRegistry instanceof SecretResolverRegistry) {
            $secretRegistry->registerConsumer('waaseyaa/ai-vector', OpenAiEmbeddingCredentialOperation::class);
        }

        $this->singleton(
            EmbeddingStorageInterface::class,
            fn(): EmbeddingStorageInterface => new DatabaseEmbeddingStorage(
                $this->resolve(DatabaseInterface::class),
                $this->resolveOptional(LoggerInterface::class),
            ),
        );

        // Resolve the configured provider once. fromConfig() only constructs a
        // value object from config (no I/O), returning null when unconfigured.
        $configuredProvider = EmbeddingProviderFactory::fromConfig($this->config, $secretRegistry instanceof SecretResolverRegistry ? $secretRegistry : null);
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

    public function boot(): void
    {
        // Idempotent: a long-lived worker may re-enter provider boot.
        if ($this->lifecycleDispatcher !== null) {
            return;
        }

        $dispatcher = $this->resolveOptional(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class);
        if (!$dispatcher instanceof EventDispatcherInterface) {
            return;
        }

        $storage = $this->resolve(EmbeddingStorageInterface::class);
        $logger = $this->lifecycleLogger();

        $cleanup = new EntityEmbeddingCleanupListener($storage, $logger);
        $dispatcher->addListener(EntityEvents::POST_DELETE->value, [$cleanup, 'onPostDelete']);

        $this->lifecycleDispatcher = $dispatcher;
        $this->subscribeSaveListener(new EntityEmbeddingListener(
            storage: $storage,
            logger: $logger,
            invalidateOnly: true,
        ));
    }

    public function configureHttpKernel(HttpKernel $kernel): void
    {
        if ($this->lifecycleDispatcher === null || $this->embeddingOnSave) {
            return;
        }

        $provider = $this->resolveOptional(EmbeddingProviderInterface::class);
        if (!$provider instanceof EmbeddingProviderInterface) {
            // No provider configured: HTTP saves invalidate like every other entry point.
            return;
        }

        $this->subscribeSaveListener(new EntityEmbeddingListener(
            storage: $this->resolve(EmbeddingStorageInterface::class),
            embeddingProvider: $provider,
            logger: $this->lifecycleLogger(),
            entityTypeManager: $this->resolveOptional(EntityTypeManagerInterface::class),
        ));
        $this->embeddingOnSave = true;
    }

    /** Replaces the current save listener's subscriptions with `$listener`'s. */
    private function subscribeSaveListener(EntityEmbeddingListener $listener): void
    {
        $dispatcher = $this->lifecycleDispatcher;
        \assert($dispatcher instanceof EventDispatcherInterface);

        $subscriptions = [
            EntityEvents::POST_SAVE->value => 'onPostSave',
            RevisionPointerMovedEvent::class => 'onRevisionPointerMoved',
            EntityEvents::REVISION_REVERTED->value => 'onRevisionReverted',
        ];
        foreach ($subscriptions as $event => $method) {
            if ($this->saveListener !== null) {
                $dispatcher->removeListener($event, [$this->saveListener, $method]);
            }
            $dispatcher->addListener($event, [$listener, $method]);
        }
        $this->saveListener = $listener;
    }

    private function lifecycleLogger(): LoggerInterface
    {
        $logger = $this->resolveOptional(LoggerInterface::class);

        return $logger instanceof LoggerInterface ? $logger : new NullLogger();
    }
}
