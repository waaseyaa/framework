<?php

declare(strict_types=1);

namespace Aurora\Cache\Tests\Unit;

use Aurora\Cache\Backend\MemoryBackend;
use Aurora\Cache\Backend\NullBackend;
use Aurora\Cache\CacheConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheConfiguration::class)]
final class CacheConfigurationTest extends TestCase
{
    #[Test]
    public function default_backend_is_memory_backend(): void
    {
        $config = new CacheConfiguration();

        $this->assertSame(MemoryBackend::class, $config->getDefaultBackend());
    }

    #[Test]
    public function custom_default_backend(): void
    {
        $config = new CacheConfiguration(NullBackend::class);

        $this->assertSame(NullBackend::class, $config->getDefaultBackend());
    }

    #[Test]
    public function unmapped_bin_returns_default(): void
    {
        $config = new CacheConfiguration();

        $this->assertSame(MemoryBackend::class, $config->getBackendForBin('unknown_bin'));
    }

    #[Test]
    public function mapped_bin_returns_specific_backend(): void
    {
        $config = new CacheConfiguration(MemoryBackend::class, [
            'cache_config' => NullBackend::class,
        ]);

        $this->assertSame(NullBackend::class, $config->getBackendForBin('cache_config'));
    }

    #[Test]
    public function set_backend_for_bin_at_runtime(): void
    {
        $config = new CacheConfiguration();

        $config->setBackendForBin('cache_entity', NullBackend::class);

        $this->assertSame(NullBackend::class, $config->getBackendForBin('cache_entity'));
    }

    #[Test]
    public function get_bin_mapping_returns_all_mappings(): void
    {
        $config = new CacheConfiguration(MemoryBackend::class, [
            'cache_config' => NullBackend::class,
            'cache_entity' => NullBackend::class,
        ]);

        $mapping = $config->getBinMapping();

        $this->assertCount(2, $mapping);
        $this->assertSame(NullBackend::class, $mapping['cache_config']);
        $this->assertSame(NullBackend::class, $mapping['cache_entity']);
    }

    #[Test]
    public function constructor_mappings_override_default(): void
    {
        $config = new CacheConfiguration(MemoryBackend::class, [
            'cache_render' => NullBackend::class,
        ]);

        // Mapped bin returns specific backend.
        $this->assertSame(NullBackend::class, $config->getBackendForBin('cache_render'));
        // Other bins return default.
        $this->assertSame(MemoryBackend::class, $config->getBackendForBin('cache_default'));
    }
}
