<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector\Tests\Integration;

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
        file_put_contents($this->projectRoot . '/config/waaseyaa.php', <<<'PHP'
            <?php
            return [
                'database' => ':memory:',
                'environment' => 'testing',
                // Binds EmbeddingProviderInterface. Nothing here calls the endpoint.
                'ai' => ['embedding_provider' => 'ollama', 'ollama_endpoint' => 'http://127.0.0.1:9/api/embeddings'],
            ];
            PHP);
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
        self::assertSame($storage, $this->property($cleanup, 'storage'), 'the cleanup listener uses the bound storage');
        self::assertSame(
            $storage,
            $this->property($this->resolve($kernel, SemanticIndexWarmer::class), 'embeddingStorage'),
            'the warmer uses the bound storage',
        );
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
        foreach ((fn() => $this->providers)->call($kernel) as $provider) {
            if ($provider instanceof AiVectorServiceProvider) {
                return $provider->resolve($abstract);
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
