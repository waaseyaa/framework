<?php

declare(strict_types=1);

namespace Waaseyaa\Plugin\Tests\Unit;

use Waaseyaa\Cache\Backend\MemoryBackend;
use Waaseyaa\Plugin\Attribute\WaaseyaaPlugin;
use Waaseyaa\Plugin\DefaultPluginManager;
use Waaseyaa\Plugin\Definition\PluginDefinition;
use Waaseyaa\Plugin\Discovery\AttributeDiscovery;
use Waaseyaa\Plugin\Discovery\PluginDiscoveryInterface;
use Waaseyaa\Plugin\Tests\Fixtures\AnotherPlugin;
use Waaseyaa\Plugin\Tests\Fixtures\TestPlugin;
use PHPUnit\Framework\TestCase;

final class DefaultPluginManagerTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = dirname(__DIR__) . '/Fixtures';
    }

    public function testGetDefinitions(): void
    {
        $discovery = new AttributeDiscovery(
            directories: [$this->fixturesDir],
            attributeClass: WaaseyaaPlugin::class,
        );

        $manager = new DefaultPluginManager($discovery);
        $definitions = $manager->getDefinitions();

        $this->assertCount(3, $definitions);
        $this->assertArrayHasKey('test_plugin', $definitions);
        $this->assertArrayHasKey('another_plugin', $definitions);
        $this->assertArrayHasKey('knowledge_tooling_example', $definitions);
    }

    public function testGetDefinition(): void
    {
        $discovery = new AttributeDiscovery(
            directories: [$this->fixturesDir],
            attributeClass: WaaseyaaPlugin::class,
        );

        $manager = new DefaultPluginManager($discovery);
        $definition = $manager->getDefinition('test_plugin');

        $this->assertInstanceOf(PluginDefinition::class, $definition);
        $this->assertSame('test_plugin', $definition->id);
        $this->assertSame('Test Plugin', $definition->label);
        $this->assertSame(TestPlugin::class, $definition->class);
    }

    public function testGetDefinitionThrowsForMissing(): void
    {
        $discovery = new AttributeDiscovery(
            directories: [$this->fixturesDir],
            attributeClass: WaaseyaaPlugin::class,
        );

        $manager = new DefaultPluginManager($discovery);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Plugin 'nonexistent' does not exist.");

        $manager->getDefinition('nonexistent');
    }

    public function testHasDefinition(): void
    {
        $discovery = new AttributeDiscovery(
            directories: [$this->fixturesDir],
            attributeClass: WaaseyaaPlugin::class,
        );

        $manager = new DefaultPluginManager($discovery);

        $this->assertTrue($manager->hasDefinition('test_plugin'));
        $this->assertTrue($manager->hasDefinition('another_plugin'));
        $this->assertTrue($manager->hasDefinition('knowledge_tooling_example'));
        $this->assertFalse($manager->hasDefinition('nonexistent'));
    }

    public function testCreateInstance(): void
    {
        $discovery = new AttributeDiscovery(
            directories: [$this->fixturesDir],
            attributeClass: WaaseyaaPlugin::class,
        );

        $manager = new DefaultPluginManager($discovery);
        $instance = $manager->createInstance('test_plugin');

        $this->assertInstanceOf(TestPlugin::class, $instance);
        $this->assertSame('test_plugin', $instance->getPluginId());
        $this->assertSame('Test Plugin', $instance->getPluginDefinition()->label);
    }

    public function testCreateInstanceWithConfiguration(): void
    {
        $discovery = new AttributeDiscovery(
            directories: [$this->fixturesDir],
            attributeClass: WaaseyaaPlugin::class,
        );

        $manager = new DefaultPluginManager($discovery);
        $config = ['setting' => 'value'];
        $instance = $manager->createInstance('test_plugin', $config);

        $this->assertInstanceOf(TestPlugin::class, $instance);
        $this->assertSame('test_plugin', $instance->getPluginId());
    }

    public function testCachingWorks(): void
    {
        $callCount = 0;
        $discovery = $this->createStub(PluginDiscoveryInterface::class);
        $discovery->method('getDefinitions')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                return [
                    'test_plugin' => new PluginDefinition(
                        id: 'test_plugin',
                        label: 'Test Plugin',
                        class: TestPlugin::class,
                    ),
                ];
            });

        $cache = new MemoryBackend();
        $manager = new DefaultPluginManager($discovery, $cache);

        // First call: should invoke discovery and populate cache.
        $definitions1 = $manager->getDefinitions();
        $this->assertCount(1, $definitions1);
        $this->assertSame(1, $callCount);

        // Create a new manager with the same cache. Discovery should NOT be
        // called again because definitions are cached.
        $manager2 = new DefaultPluginManager($discovery, $cache);
        $definitions2 = $manager2->getDefinitions();
        $this->assertCount(1, $definitions2);
        $this->assertSame(1, $callCount, 'Discovery should not have been called again');
    }

    public function testClearCachedDefinitions(): void
    {
        $callCount = 0;
        $discovery = $this->createStub(PluginDiscoveryInterface::class);
        $discovery->method('getDefinitions')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                return [
                    'test_plugin' => new PluginDefinition(
                        id: 'test_plugin',
                        label: 'Test Plugin',
                        class: TestPlugin::class,
                    ),
                ];
            });

        $cache = new MemoryBackend();
        $manager = new DefaultPluginManager($discovery, $cache);

        // First call: discovery invoked.
        $manager->getDefinitions();
        $this->assertSame(1, $callCount);

        // Clear cache and request definitions again.
        $manager->clearCachedDefinitions();
        $manager->getDefinitions();
        $this->assertSame(2, $callCount, 'Discovery should be called again after clearing cache');
    }

    public function testDefinitionsAreMemoized(): void
    {
        $callCount = 0;
        $discovery = $this->createStub(PluginDiscoveryInterface::class);
        $discovery->method('getDefinitions')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                return [
                    'test_plugin' => new PluginDefinition(
                        id: 'test_plugin',
                        label: 'Test Plugin',
                        class: TestPlugin::class,
                    ),
                ];
            });

        $manager = new DefaultPluginManager($discovery);

        // Multiple calls on the same manager should only invoke discovery once.
        $manager->getDefinitions();
        $manager->getDefinitions();
        $manager->getDefinitions();

        $this->assertSame(1, $callCount, 'Discovery should only be called once due to memoization');
    }

    public function testCreateInstanceForAnotherPlugin(): void
    {
        $discovery = new AttributeDiscovery(
            directories: [$this->fixturesDir],
            attributeClass: WaaseyaaPlugin::class,
        );

        $manager = new DefaultPluginManager($discovery);
        $instance = $manager->createInstance('another_plugin');

        $this->assertInstanceOf(AnotherPlugin::class, $instance);
        $this->assertSame('another_plugin', $instance->getPluginId());
        $this->assertSame('Another Plugin', $instance->getPluginDefinition()->label);
    }
}
