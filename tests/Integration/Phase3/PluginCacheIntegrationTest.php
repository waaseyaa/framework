<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Phase3;

use Aurora\Cache\Backend\MemoryBackend;
use Aurora\Cache\CacheBackendInterface;
use Aurora\Cache\CacheFactory;
use Aurora\Cache\CacheTagsInvalidator;
use Aurora\Plugin\Attribute\AuroraPlugin;
use Aurora\Plugin\DefaultPluginManager;
use Aurora\Plugin\Discovery\AttributeDiscovery;
use Aurora\Plugin\Discovery\PluginDiscoveryInterface;
use Aurora\Plugin\Definition\PluginDefinition;
use Aurora\Tests\Integration\Phase3\Fixtures\GreeterPlugin;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests verifying aurora/plugin works with aurora/cache.
 *
 * These tests exercise the cross-package dependency where
 * DefaultPluginManager uses CacheBackendInterface from aurora/cache
 * to persist discovered plugin definitions.
 */
final class PluginCacheIntegrationTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__ . '/Fixtures';
    }

    /**
     * Verify that plugin discovery + MemoryBackend caching works end-to-end.
     *
     * First call discovers plugins and caches them. Second manager instance
     * reads from cache and never calls discovery.
     */
    public function testPluginManagerCachesDefinitionsInMemoryBackend(): void
    {
        $discoveryCallCount = 0;
        $discovery = $this->createMock(PluginDiscoveryInterface::class);
        $discovery->method('getDefinitions')
            ->willReturnCallback(function () use (&$discoveryCallCount) {
                $discoveryCallCount++;
                return [
                    'greeter' => new PluginDefinition(
                        id: 'greeter',
                        label: 'Greeter',
                        class: GreeterPlugin::class,
                        description: 'A greeting plugin',
                    ),
                ];
            });

        $cache = new MemoryBackend();

        // First manager: discovers and caches.
        $manager1 = new DefaultPluginManager($discovery, $cache);
        $definitions = $manager1->getDefinitions();
        $this->assertCount(1, $definitions);
        $this->assertSame('greeter', $definitions['greeter']->id);
        $this->assertSame(1, $discoveryCallCount);

        // Verify the cache backend actually has the data.
        $cached = $cache->get('plugin_definitions');
        $this->assertNotFalse($cached);
        $this->assertTrue($cached->valid);
        $this->assertIsArray($cached->data);
        $this->assertArrayHasKey('greeter', $cached->data);

        // Second manager: reads from cache, never touches discovery.
        $manager2 = new DefaultPluginManager($discovery, $cache);
        $definitions2 = $manager2->getDefinitions();
        $this->assertCount(1, $definitions2);
        $this->assertSame('greeter', $definitions2['greeter']->id);
        $this->assertSame(1, $discoveryCallCount, 'Discovery should not be invoked when cache hit');
    }

    /**
     * Verify that clearCachedDefinitions removes the cache entry and forces
     * re-discovery on the next call.
     */
    public function testClearCachedDefinitionsRemovesCacheEntry(): void
    {
        $discoveryCallCount = 0;
        $discovery = $this->createMock(PluginDiscoveryInterface::class);
        $discovery->method('getDefinitions')
            ->willReturnCallback(function () use (&$discoveryCallCount) {
                $discoveryCallCount++;
                return [
                    'greeter' => new PluginDefinition(
                        id: 'greeter',
                        label: 'Greeter',
                        class: GreeterPlugin::class,
                    ),
                ];
            });

        $cache = new MemoryBackend();
        $manager = new DefaultPluginManager($discovery, $cache);

        // Discover and cache.
        $manager->getDefinitions();
        $this->assertSame(1, $discoveryCallCount);
        $this->assertNotFalse($cache->get('plugin_definitions'));

        // Clear and verify cache entry is gone.
        $manager->clearCachedDefinitions();
        $this->assertFalse($cache->get('plugin_definitions'));

        // Next call must re-discover.
        $manager->getDefinitions();
        $this->assertSame(2, $discoveryCallCount);
    }

    /**
     * Full end-to-end: real attribute discovery + real MemoryBackend cache +
     * plugin instantiation.
     */
    public function testEndToEndDiscoveryCacheAndInstantiation(): void
    {
        $discovery = new AttributeDiscovery(
            directories: [$this->fixturesDir],
            attributeClass: AuroraPlugin::class,
        );

        $cache = new MemoryBackend();
        $manager = new DefaultPluginManager($discovery, $cache);

        // Discover plugins.
        $this->assertTrue($manager->hasDefinition('greeter'));

        // Cache should now be populated.
        $cached = $cache->get('plugin_definitions');
        $this->assertNotFalse($cached);
        $this->assertTrue($cached->valid);

        // Instantiate the plugin.
        $instance = $manager->createInstance('greeter');
        $this->assertInstanceOf(GreeterPlugin::class, $instance);
        $this->assertSame('greeter', $instance->getPluginId());
        $this->assertSame('Greeter', $instance->getPluginDefinition()->label);
    }

    /**
     * Verify CacheFactory can create backends used by the plugin manager.
     */
    public function testCacheFactoryBackendWorksWithPluginManager(): void
    {
        $factory = new CacheFactory();
        $backend = $factory->get('plugin_discovery');

        // CacheFactory should return a CacheBackendInterface instance.
        $this->assertInstanceOf(CacheBackendInterface::class, $backend);

        $discovery = new AttributeDiscovery(
            directories: [$this->fixturesDir],
            attributeClass: AuroraPlugin::class,
        );

        $manager = new DefaultPluginManager($discovery, $backend);
        $definitions = $manager->getDefinitions();

        $this->assertNotEmpty($definitions);
        $this->assertArrayHasKey('greeter', $definitions);
    }

    /**
     * Verify NullBackend works with plugin manager (no caching, always discovers).
     */
    public function testNullBackendMeansNoCache(): void
    {
        $discoveryCallCount = 0;
        $discovery = $this->createMock(PluginDiscoveryInterface::class);
        $discovery->method('getDefinitions')
            ->willReturnCallback(function () use (&$discoveryCallCount) {
                $discoveryCallCount++;
                return [
                    'greeter' => new PluginDefinition(
                        id: 'greeter',
                        label: 'Greeter',
                        class: GreeterPlugin::class,
                    ),
                ];
            });

        $nullCache = new \Aurora\Cache\Backend\NullBackend();
        $manager = new DefaultPluginManager($discovery, $nullCache);

        $manager->getDefinitions();
        $this->assertSame(1, $discoveryCallCount);

        // With NullBackend, a second manager instance must re-discover.
        $manager2 = new DefaultPluginManager($discovery, $nullCache);
        $manager2->getDefinitions();
        $this->assertSame(2, $discoveryCallCount, 'NullBackend should not persist definitions');
    }
}
