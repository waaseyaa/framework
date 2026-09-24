<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\AiVector\Fixtures;

use Waaseyaa\AI\Vector\EmbeddingProviderInterface;
use Waaseyaa\AI\Vector\EmbeddingStorageInterface;
use Waaseyaa\AI\Vector\Testing\FakeEmbeddingProvider;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * A host provider that binds its own embedding storage and provider, used to
 * prove every ai-vector consumer follows the same kernel-services rule
 * (FW-AIV-COMP-01).
 *
 * @internal Test fixture.
 */
final class HostEmbeddingServicesProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton(EmbeddingStorageInterface::class, static fn(): EmbeddingStorageInterface => new HostEmbeddingStorage());
        $this->singleton(EmbeddingProviderInterface::class, static fn(): EmbeddingProviderInterface => new FakeEmbeddingProvider(dimensions: 8));
    }
}
