<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\AiVector;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\AI\Vector\AiVectorServiceProvider;
use Waaseyaa\AI\Vector\EmbeddingProviderInterface;
use Waaseyaa\AI\Vector\EmbeddingStorageInterface;
use Waaseyaa\AI\Vector\EntityEmbeddingCleanupListener;
use Waaseyaa\AI\Vector\EntityEmbeddingListener;
use Waaseyaa\AI\Vector\SemanticIndexWarmer;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\Kernel\ConsoleKernel;
use Waaseyaa\Foundation\Kernel\HttpKernel;

/**
 * FW-AIV-COMP-01 (#3139): one composition owner. The embedding storage and
 * provider bound by `AiVectorServiceProvider` are the instances every entry
 * point uses. At the #3139 base, `HttpKernel` built its own storage and
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
        file_put_contents($this->projectRoot . '/composer.json', json_encode([
            'name' => 'waaseyaa/aiv-composition-test',
            'extra' => ['waaseyaa' => ['providers' => [AiVectorServiceProvider::class]]],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
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

    /** @return array{EmbeddingStorageInterface, EmbeddingProviderInterface} */
    private function boundServices(AbstractKernel $kernel): array
    {
        $storage = $this->resolve($kernel, EmbeddingStorageInterface::class);
        $provider = $this->resolve($kernel, EmbeddingProviderInterface::class);
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
