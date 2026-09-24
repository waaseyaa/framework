<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\AI\Vector\AiVectorServiceProvider;
use Waaseyaa\AI\Vector\DatabaseEmbeddingStorage;
use Waaseyaa\AI\Vector\EmbeddingProviderInterface;
use Waaseyaa\AI\Vector\EmbeddingStorageInterface;
use Waaseyaa\AI\Vector\EntityEmbeddingCleanupListener;
use Waaseyaa\AI\Vector\EntityEmbeddingListener;
use Waaseyaa\AI\Vector\SemanticIndexWarmer;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\EntityStorage\Event\RevisionPointerMovedEvent;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;

/**
 * Regression lock for the `semantic:warm` / `semantic:refresh` CLI crash:
 * before this provider existed, `packages/ai-vector` bound nothing, so the
 * kernel container autowiring `SemanticIndexWarmer` threw
 * `No binding for "Waaseyaa\AI\Vector\EmbeddingStorageInterface"`.
 */
#[CoversClass(AiVectorServiceProvider::class)]
final class AiVectorServiceProviderTest extends TestCase
{
    #[Test]
    public function resolvesEmbeddingStorageThroughKernelServicesDatabase(): void
    {
        $provider = $this->providerWithKernelServices([]);

        $storage = $provider->resolve(EmbeddingStorageInterface::class);

        $this->assertInstanceOf(DatabaseEmbeddingStorage::class, $storage);
    }

    #[Test]
    public function resolvesSemanticIndexWarmerWithoutThrowing(): void
    {
        $provider = $this->providerWithKernelServices([]);

        $warmer = $provider->resolve(SemanticIndexWarmer::class);

        $this->assertInstanceOf(SemanticIndexWarmer::class, $warmer);
    }

    #[Test]
    public function warmerGracefullyDegradesWithNoConfiguredProvider(): void
    {
        $provider = $this->providerWithKernelServices([]);

        $warmer = $provider->resolve(SemanticIndexWarmer::class);
        $report = $warmer->warm(['node']);

        $this->assertSame('skipped_no_provider', $report['status']);
    }

    #[Test]
    public function embeddingProviderIsUnboundWhenNotConfigured(): void
    {
        $provider = $this->providerWithKernelServices([]);

        // No `ai.embedding_provider`: the interface stays unbound so the
        // vector.search resolver closure and the warmer both see null.
        $this->assertNull($provider->resolveOptional(EmbeddingProviderInterface::class));
    }

    #[Test]
    public function embeddingProviderIsBoundWhenConfigured(): void
    {
        $provider = $this->providerWithKernelServices(['ai' => ['embedding_provider' => 'ollama']]);

        // A configured provider is now resolvable from the container, so a
        // host-wired vector.search tool resolves a real embedding provider.
        $this->assertInstanceOf(
            EmbeddingProviderInterface::class,
            $provider->resolve(EmbeddingProviderInterface::class),
        );
    }

    #[Test]
    public function boot_registers_vector_invalidation_for_saves_and_deletes_on_the_kernel_dispatcher(): void
    {
        [$provider, $dispatcher, $database] = $this->lifecycleProvider([]);
        $storage = $provider->resolve(EmbeddingStorageInterface::class);
        $storage->store('node', '1', [1.0, 0.0]);
        $storage->store('note', '2', [0.0, 1.0]);

        $provider->boot();
        $dispatcher->dispatch(new EntityEvent($this->publishedNode()), EntityEvents::POST_SAVE->value);
        $dispatcher->dispatch(new EntityEvent(new ProviderLifecycleEntity(2, 'note')), EntityEvents::POST_DELETE->value);

        self::assertSame(0, $this->vectorCount($database), 'outside HTTP, a save and a delete both remove the vector');
    }

    #[Test]
    public function booting_twice_registers_each_lifecycle_listener_once(): void
    {
        [$provider, $dispatcher] = $this->lifecycleProvider([]);

        $provider->boot();
        $provider->boot();

        self::assertSame(1, $this->aiVectorListenerCount($dispatcher, EntityEvents::POST_SAVE->value));
        self::assertSame(1, $this->aiVectorListenerCount($dispatcher, EntityEvents::POST_DELETE->value));
        self::assertSame(1, $this->aiVectorListenerCount($dispatcher, RevisionPointerMovedEvent::class));
    }

    #[Test]
    public function configuring_the_http_kernel_replaces_the_save_listener_once_when_a_provider_is_bound(): void
    {
        // Binds an Ollama provider; nothing here calls its endpoint.
        [$provider, $dispatcher] = $this->lifecycleProvider(['ai' => ['embedding_provider' => 'ollama', 'ollama_endpoint' => 'http://127.0.0.1:9/api/embeddings']]);
        $provider->boot();
        $invalidating = $this->saveListener($dispatcher);

        $provider->configureHttpKernel(new HttpKernel(sys_get_temp_dir()));
        $embedding = $this->saveListener($dispatcher);
        $provider->configureHttpKernel(new HttpKernel(sys_get_temp_dir()));

        self::assertNotSame($invalidating, $embedding, 'HTTP with a provider swaps in the embedding listener');
        self::assertSame($embedding, $this->saveListener($dispatcher), 'a second configuration changes nothing');
        foreach ([EntityEvents::POST_SAVE->value, RevisionPointerMovedEvent::class, EntityEvents::REVISION_REVERTED->value] as $event) {
            self::assertSame(1, $this->aiVectorListenerCount($dispatcher, $event), $event);
        }
    }

    #[Test]
    public function configuring_the_http_kernel_without_a_provider_keeps_invalidating(): void
    {
        [$provider, $dispatcher, $database] = $this->lifecycleProvider([]);
        $provider->resolve(EmbeddingStorageInterface::class)->store('node', '1', [1.0, 0.0]);

        $provider->boot();
        $invalidating = $this->saveListener($dispatcher);
        $provider->configureHttpKernel(new HttpKernel(sys_get_temp_dir()));
        $dispatcher->dispatch(new EntityEvent($this->publishedNode()), EntityEvents::POST_SAVE->value);

        self::assertSame($invalidating, $this->saveListener($dispatcher));
        self::assertSame(0, $this->vectorCount($database), 'the save removed the vector');
    }

    private function saveListener(SymfonyEventDispatcherAdapter $dispatcher): EntityEmbeddingListener
    {
        foreach ($dispatcher->getListeners(EntityEvents::POST_SAVE->value) as $listener) {
            if (is_array($listener) && $listener[0] instanceof EntityEmbeddingListener) {
                return $listener[0];
            }
        }

        self::fail('No EntityEmbeddingListener is registered for POST_SAVE.');
    }

    #[Test]
    public function lifecycle_listeners_use_an_earlier_storage_binding_from_kernel_services(): void
    {
        $hostStorage = $this->createMock(EmbeddingStorageInterface::class);
        $hostStorage->expects($this->once())->method('delete')->with('note', '2');
        [$provider, $dispatcher, $database] = $this->lifecycleProvider([], $hostStorage);
        $provider->resolve(EmbeddingStorageInterface::class)->store('note', '2', [1.0]);

        $provider->boot();
        $dispatcher->dispatch(new EntityEvent(new ProviderLifecycleEntity(2, 'note')), EntityEvents::POST_DELETE->value);

        self::assertSame(1, $this->vectorCount($database), 'the provider\'s own storage is not the one consumers use');
    }

    private function publishedNode(): ProviderLifecycleEntity
    {
        return new ProviderLifecycleEntity(1, 'node', ['status' => 1, 'workflow_state' => 'published', 'title' => 'Indexable']);
    }

    /**
     * A provider over an in-memory database with the ai-vector table, a real
     * kernel dispatcher, and no entity type manager, so the embedding listener
     * indexes the event's own entity. Like the kernel bus, the fake kernel
     * services return an earlier binding when there is one and otherwise this
     * provider's own.
     *
     * @param array<string, mixed> $config
     * @param EmbeddingStorageInterface|null $earlierBinding what kernel services return for the storage interface, as when an earlier provider binds it
     * @return array{AiVectorServiceProvider, SymfonyEventDispatcherAdapter, DBALDatabase}
     */
    private function lifecycleProvider(array $config, ?EmbeddingStorageInterface $earlierBinding = null): array
    {
        $database = DBALDatabase::createSqlite(':memory:');
        RuntimeSchemaMigrations::aiVector($database);
        $dispatcher = new SymfonyEventDispatcherAdapter();

        $provider = new AiVectorServiceProvider();
        $provider->setKernelContext(sys_get_temp_dir(), $config, []);
        // The real bus never re-enters a provider for an interface it doesn't
        // bind; the guard keeps this fake from recursing through resolve()'s
        // kernel-services fallback.
        $resolving = false;
        $ownBinding = static function (string $abstract) use ($provider, &$resolving): ?object {
            if ($resolving) {
                return null;
            }
            $resolving = true;
            try {
                return $provider->resolveOptional($abstract);
            } finally {
                $resolving = false;
            }
        };
        $provider->setKernelServices(new class ($database, $dispatcher, $earlierBinding, $ownBinding) implements KernelServicesInterface {
            public function __construct(
                private readonly DBALDatabase $database,
                private readonly SymfonyEventDispatcherAdapter $dispatcher,
                private readonly ?EmbeddingStorageInterface $earlierBinding,
                private readonly \Closure $ownBinding,
            ) {}

            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    DatabaseInterface::class => $this->database,
                    \Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class => $this->dispatcher,
                    EmbeddingStorageInterface::class => $this->earlierBinding ?? ($this->ownBinding)($abstract),
                    EmbeddingProviderInterface::class => ($this->ownBinding)($abstract),
                    default => null,
                };
            }
        });
        $provider->register();

        return [$provider, $dispatcher, $database];
    }

    private function vectorCount(DBALDatabase $database): int
    {
        return (int) $database->getConnection()->fetchOne('SELECT COUNT(*) FROM embeddings');
    }

    private function aiVectorListenerCount(SymfonyEventDispatcherAdapter $dispatcher, string $event): int
    {
        $count = 0;
        foreach ($dispatcher->getListeners($event) as $listener) {
            $object = is_array($listener) ? $listener[0] : $listener;
            if ($object instanceof EntityEmbeddingListener || $object instanceof EntityEmbeddingCleanupListener) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function providerWithKernelServices(array $config): AiVectorServiceProvider
    {
        $entityTypeManager = new EntityTypeManager(new EventDispatcher());

        $provider = new AiVectorServiceProvider();
        $provider->setKernelContext('/tmp/test', $config, []);
        $provider->setKernelServices(new class ($entityTypeManager) implements KernelServicesInterface {
            public function __construct(
                private readonly EntityTypeManagerInterface $entityTypeManager,
            ) {}

            public function get(string $abstract): ?object
            {
                if ($abstract === DatabaseInterface::class) {
                    return DBALDatabase::createSqlite(':memory:');
                }

                if ($abstract === EntityTypeManagerInterface::class) {
                    return $this->entityTypeManager;
                }

                return null;
            }
        });
        $provider->register();

        return $provider;
    }
}

final readonly class ProviderLifecycleEntity implements EntityInterface
{
    /** @param array<string, mixed> $values */
    public function __construct(private int $id, private string $type, private array $values = []) {}

    public function id(): int|string|null { return $this->id; }
    public function uuid(): string { return ''; }
    public function label(): string { return (string) ($this->values['title'] ?? ''); }
    public function getEntityTypeId(): string { return $this->type; }
    public function bundle(): string { return 'default'; }
    public function isNew(): bool { return false; }
    public function get(string $name): mixed { return $this->values[$name] ?? null; }
    public function set(string $name, mixed $value): static { throw new \LogicException('Readonly'); }
    public function toArray(): array { return ['id' => $this->id] + $this->values; }
    public function language(): string { return 'en'; }
}
