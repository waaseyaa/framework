<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\AI\Vector\AiVectorServiceProvider;
use Waaseyaa\AI\Vector\EmbeddingProviderInterface;
use Waaseyaa\AI\Vector\EmbeddingStorageInterface;
use Waaseyaa\AI\Vector\SemanticIndexWarmer;
use Waaseyaa\AI\Vector\SqliteEmbeddingStorage;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;

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
    public function resolvesEmbeddingStorageThroughKernelServicesPdo(): void
    {
        $provider = $this->providerWithKernelServices([]);

        $storage = $provider->resolve(EmbeddingStorageInterface::class);

        $this->assertInstanceOf(SqliteEmbeddingStorage::class, $storage);
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
                if ($abstract === \PDO::class) {
                    return new \PDO('sqlite::memory:');
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
