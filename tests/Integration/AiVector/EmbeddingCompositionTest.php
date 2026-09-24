<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\AiVector;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\AI\Vector\AiVectorServiceProvider;
use Waaseyaa\AI\Vector\DatabaseEmbeddingStorage;
use Waaseyaa\AI\Vector\EmbeddingProviderInterface;
use Waaseyaa\AI\Vector\EmbeddingStorageInterface;
use Waaseyaa\AI\Vector\EntityEmbeddingCleanupListener;
use Waaseyaa\AI\Vector\EntityEmbeddingListener;
use Waaseyaa\AI\Vector\SemanticIndexWarmer;
use Waaseyaa\AI\Vector\Testing\FakeEmbeddingProvider;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\Kernel\ConsoleKernel;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Tests\Integration\AiVector\Fixtures\HostEmbeddingServicesProvider;
use Waaseyaa\Tests\Integration\AiVector\Fixtures\HostEmbeddingStorage;

/**
 * FW-AIV-COMP-01 (#3139): one composition owner. Every entry point uses the
 * kernel services' first binding of the embedding storage and of the
 * provider, which is `AiVectorServiceProvider`'s unless another provider
 * binds the interface first. At the #3139 base, `HttpKernel` built its own storage and
 * provider for the listeners, and `ConsoleKernel` registered no listeners.
 *
 * This is a composition test: it asserts which instances are wired, by
 * identity. The behavior of those instances is covered elsewhere.
 */
#[CoversNothing]
final class EmbeddingCompositionTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_aiv_composition_' . bin2hex(random_bytes(6));
        mkdir($this->projectRoot . '/config', 0o755, true);
        mkdir($this->projectRoot . '/storage/framework', 0o755, true);
        $this->writeConfig(withProvider: true);
        file_put_contents($this->projectRoot . '/config/entity-types.php', <<<'PHP'
            <?php
            return [
                new \Waaseyaa\Entity\EntityType(
                    id: 'note',
                    label: 'Note',
                    class: \Waaseyaa\Note\Note::class,
                    keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
                ),
            ];
            PHP);
        $this->writeProviders([AiVectorServiceProvider::class]);
    }

    protected function tearDown(): void
    {
        new \ReflectionProperty(ContentEntityBase::class, 'fieldRegistry')->setValue(null, null);
        new Filesystem()->remove($this->projectRoot);
    }

    #[Test]
    public function http_kernel_lifecycle_listeners_use_the_provider_bound_storage_and_provider(): void
    {
        $kernel = $this->boot(HttpKernel::class);
        [$storage, $provider] = $this->boundServices($kernel);

        $indexer = $this->onlyListener($kernel, EntityEvents::POST_SAVE->value, EntityEmbeddingListener::class);
        $cleanup = $this->onlyListener($kernel, EntityEvents::POST_DELETE->value, EntityEmbeddingCleanupListener::class);

        self::assertSame($storage, $this->property($indexer, 'storage'), 'the indexing listener uses the bound storage');
        self::assertSame($provider, $this->property($indexer, 'embeddingProvider'), 'the indexing listener uses the bound provider');
        self::assertFalse($this->property($indexer, 'invalidateOnly'), 'HTTP with a configured provider embeds on save');
        self::assertSame($storage, $this->property($cleanup, 'storage'), 'the cleanup listener uses the bound storage');
        self::assertSame(
            $storage,
            $this->property($this->resolve($kernel, SemanticIndexWarmer::class), 'embeddingStorage'),
            'the warmer uses the bound storage',
        );

        // SearchRouter is built with this resolver (HttpKernel::semanticSearchServices).
        $searchServices = (fn() => $this->semanticSearchServices())->call($kernel);
        self::assertIsArray($searchServices);
        self::assertSame($storage, $searchServices[0], 'search uses the bound storage');
        self::assertSame($provider, $searchServices[1], 'search uses the bound provider');
    }

    #[Test]
    public function http_kernel_without_a_configured_provider_invalidates_on_save(): void
    {
        $this->writeConfig(withProvider: false);
        $kernel = $this->boot(HttpKernel::class);

        $indexer = $this->onlyListener($kernel, EntityEvents::POST_SAVE->value, EntityEmbeddingListener::class);
        self::assertTrue($this->property($indexer, 'invalidateOnly'));
        self::assertNull($this->property($indexer, 'embeddingProvider'));
    }

    #[Test]
    public function re_entering_provider_boot_and_http_configuration_registers_each_listener_once(): void
    {
        $kernel = $this->boot(HttpKernel::class);
        $provider = $this->aiVectorProvider($kernel);
        $provider->boot();
        \assert($kernel instanceof HttpKernel);
        $provider->configureHttpKernel($kernel);

        $this->onlyListener($kernel, EntityEvents::POST_SAVE->value, EntityEmbeddingListener::class);
        $this->onlyListener($kernel, EntityEvents::POST_DELETE->value, EntityEmbeddingCleanupListener::class);
        $this->onlyListener($kernel, EntityEvents::REVISION_REVERTED->value, EntityEmbeddingListener::class);
    }

    #[Test]
    public function a_host_binding_registered_before_ai_vector_is_what_every_consumer_uses(): void
    {
        $this->writeProviders([HostEmbeddingServicesProvider::class, AiVectorServiceProvider::class]);
        $kernel = $this->boot(HttpKernel::class);
        [$storage, $provider] = $this->boundServices($kernel);

        self::assertInstanceOf(HostEmbeddingStorage::class, $storage, 'the earlier binding wins on the bus');
        $this->assertEveryConsumerUses($kernel, $storage, $provider);
    }

    #[Test]
    public function a_host_binding_registered_after_a_configured_ai_vector_is_used_by_no_consumer(): void
    {
        $this->writeProviders([AiVectorServiceProvider::class, HostEmbeddingServicesProvider::class]);
        $kernel = $this->boot(HttpKernel::class);
        [$storage, $provider] = $this->boundServices($kernel);

        self::assertInstanceOf(DatabaseEmbeddingStorage::class, $storage, 'ai-vector\'s binding comes first');
        self::assertNotInstanceOf(FakeEmbeddingProvider::class, $provider, 'ai-vector\'s configured provider comes first');
        $this->assertEveryConsumerUses($kernel, $storage, $provider);
    }

    #[Test]
    public function a_later_host_provider_binding_reaches_every_consumer_when_ai_vector_binds_no_provider(): void
    {
        // The rule is per interface: with no configured provider, ai-vector
        // binds only the storage, so the later host binding is the first
        // provider binding.
        $this->writeConfig(withProvider: false);
        $this->writeProviders([AiVectorServiceProvider::class, HostEmbeddingServicesProvider::class]);
        $kernel = $this->boot(HttpKernel::class);
        [$storage, $provider] = $this->boundServices($kernel);

        self::assertInstanceOf(DatabaseEmbeddingStorage::class, $storage, 'ai-vector\'s storage binding comes first');
        self::assertInstanceOf(FakeEmbeddingProvider::class, $provider, 'the host\'s is the only provider binding');
        self::assertFalse(
            $this->property($this->onlyListener($kernel, EntityEvents::POST_SAVE->value, EntityEmbeddingListener::class), 'invalidateOnly'),
            'HTTP embeds on save with the host provider',
        );
        $this->assertEveryConsumerUses($kernel, $storage, $provider);
    }

    #[Test]
    public function console_kernel_listeners_follow_an_earlier_host_binding_too(): void
    {
        $this->writeProviders([HostEmbeddingServicesProvider::class, AiVectorServiceProvider::class]);
        $kernel = $this->boot(ConsoleKernel::class);
        [$storage] = $this->boundServices($kernel);

        self::assertInstanceOf(HostEmbeddingStorage::class, $storage);
        self::assertSame($storage, $this->property($this->onlyListener($kernel, EntityEvents::POST_SAVE->value, EntityEmbeddingListener::class), 'storage'));
        self::assertSame($storage, $this->property($this->onlyListener($kernel, EntityEvents::POST_DELETE->value, EntityEmbeddingCleanupListener::class), 'storage'));
        self::assertSame($storage, $this->property($this->resolve($kernel, SemanticIndexWarmer::class), 'embeddingStorage'));
    }

    #[Test]
    public function console_kernel_registers_lifecycle_listeners_with_the_provider_bound_storage(): void
    {
        $kernel = $this->boot(ConsoleKernel::class);
        [$storage] = $this->boundServices($kernel);

        $indexer = $this->onlyListener($kernel, EntityEvents::POST_SAVE->value, EntityEmbeddingListener::class);
        $cleanup = $this->onlyListener($kernel, EntityEvents::POST_DELETE->value, EntityEmbeddingCleanupListener::class);

        self::assertSame($storage, $this->property($indexer, 'storage'));
        self::assertSame($storage, $this->property($cleanup, 'storage'));
        self::assertTrue($this->property($indexer, 'invalidateOnly'), 'outside HTTP, saves only remove vectors');
        self::assertNull($this->property($indexer, 'embeddingProvider'), 'outside HTTP, the provider is never called');
    }

    /** @param list<class-string> $providers in registration order */
    private function writeProviders(array $providers): void
    {
        file_put_contents($this->projectRoot . '/composer.json', json_encode([
            'name' => 'waaseyaa/aiv-composition-test',
            'extra' => ['waaseyaa' => ['providers' => $providers]],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    /** Listeners, warmer, search and the bus all hold exactly these instances. */
    private function assertEveryConsumerUses(AbstractKernel $kernel, EmbeddingStorageInterface $storage, EmbeddingProviderInterface $provider): void
    {
        $indexer = $this->onlyListener($kernel, EntityEvents::POST_SAVE->value, EntityEmbeddingListener::class);
        self::assertSame($storage, $this->property($indexer, 'storage'), 'indexing listener storage');
        self::assertSame($provider, $this->property($indexer, 'embeddingProvider'), 'indexing listener provider');
        self::assertSame($storage, $this->property($this->onlyListener($kernel, EntityEvents::POST_DELETE->value, EntityEmbeddingCleanupListener::class), 'storage'), 'cleanup listener storage');
        $warmer = $this->resolve($kernel, SemanticIndexWarmer::class);
        self::assertSame($storage, $this->property($warmer, 'embeddingStorage'), 'warmer storage');
        self::assertSame($provider, $this->property($warmer, 'embeddingProvider'), 'warmer provider');
        $search = (fn() => $this->semanticSearchServices())->call($kernel);
        self::assertIsArray($search);
        self::assertSame($storage, $search[0], 'search storage');
        self::assertSame($provider, $search[1], 'search provider');
    }

    private function writeConfig(bool $withProvider): void
    {
        // A configured provider binds EmbeddingProviderInterface. Nothing here calls the endpoint.
        $ai = $withProvider ? "'ai' => ['embedding_provider' => 'ollama', 'ollama_endpoint' => 'http://127.0.0.1:9/api/embeddings']," : '';
        file_put_contents($this->projectRoot . '/config/waaseyaa.php', "<?php return ['database' => ':memory:', 'environment' => 'testing', {$ai}];");
    }

    /** @param class-string<AbstractKernel> $class */
    private function boot(string $class): AbstractKernel
    {
        $kernel = new $class($this->projectRoot);
        (fn() => $this->boot())->call($kernel);

        return $kernel;
    }

    /**
     * What the kernel services bus resolves (first binding wins), the rule
     * every consumer must follow.
     *
     * @return array{EmbeddingStorageInterface, EmbeddingProviderInterface}
     */
    private function boundServices(AbstractKernel $kernel): array
    {
        $container = $kernel->buildHandlerContainer();
        $storage = $container->get(EmbeddingStorageInterface::class);
        $provider = $container->get(EmbeddingProviderInterface::class);
        self::assertInstanceOf(EmbeddingStorageInterface::class, $storage);
        self::assertInstanceOf(EmbeddingProviderInterface::class, $provider);

        return [$storage, $provider];
    }

    private function resolve(AbstractKernel $kernel, string $abstract): object
    {
        return $this->aiVectorProvider($kernel)->resolve($abstract);
    }

    private function aiVectorProvider(AbstractKernel $kernel): AiVectorServiceProvider
    {
        foreach ((fn() => $this->providers)->call($kernel) as $provider) {
            if ($provider instanceof AiVectorServiceProvider) {
                return $provider;
            }
        }

        self::fail('AiVectorServiceProvider is not registered.');
    }

    private function onlyListener(AbstractKernel $kernel, string $event, string $class): object
    {
        $dispatcher = (fn() => $this->dispatcher)->call($kernel);
        $matches = [];
        foreach ($dispatcher->getListeners($event) as $listener) {
            $object = is_array($listener) ? $listener[0] : $listener;
            if ($object instanceof $class) {
                $matches[] = $object;
            }
        }
        self::assertCount(1, $matches, sprintf('exactly one %s on %s', $class, $event));

        return $matches[0];
    }

    private function property(object $object, string $name): mixed
    {
        return new \ReflectionProperty($object, $name)->getValue($object);
    }
}
