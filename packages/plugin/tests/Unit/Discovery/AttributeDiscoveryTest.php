<?php

declare(strict_types=1);

namespace Waaseyaa\Plugin\Tests\Unit\Discovery;

use Waaseyaa\Plugin\Attribute\WaaseyaaPlugin;
use Waaseyaa\Plugin\Definition\PluginDefinition;
use Waaseyaa\Plugin\Discovery\AttributeDiscovery;
use Waaseyaa\Plugin\Tests\Fixtures\AnotherPlugin;
use Waaseyaa\Plugin\Tests\Fixtures\KnowledgeToolingExamplePlugin;
use Waaseyaa\Plugin\Tests\Fixtures\TestPlugin;
use PHPUnit\Framework\TestCase;

final class AttributeDiscoveryTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = dirname(__DIR__, 2) . '/Fixtures';
    }

    public function testDiscoverPlugins(): void
    {
        $discovery = new AttributeDiscovery(
            directories: [$this->fixturesDir],
            attributeClass: WaaseyaaPlugin::class,
        );

        $definitions = $discovery->getDefinitions();

        $this->assertCount(3, $definitions);
        $this->assertArrayHasKey('test_plugin', $definitions);
        $this->assertArrayHasKey('another_plugin', $definitions);
        $this->assertArrayHasKey('knowledge_tooling_example', $definitions);
    }

    public function testDefinitionsHaveCorrectData(): void
    {
        $discovery = new AttributeDiscovery(
            directories: [$this->fixturesDir],
            attributeClass: WaaseyaaPlugin::class,
        );

        $definitions = $discovery->getDefinitions();

        // Test the TestPlugin definition.
        $testDef = $definitions['test_plugin'];
        $this->assertInstanceOf(PluginDefinition::class, $testDef);
        $this->assertSame('test_plugin', $testDef->id);
        $this->assertSame('Test Plugin', $testDef->label);
        $this->assertSame(TestPlugin::class, $testDef->class);
        $this->assertSame('A test plugin', $testDef->description);

        // Test the AnotherPlugin definition.
        $anotherDef = $definitions['another_plugin'];
        $this->assertInstanceOf(PluginDefinition::class, $anotherDef);
        $this->assertSame('another_plugin', $anotherDef->id);
        $this->assertSame('Another Plugin', $anotherDef->label);
        $this->assertSame(AnotherPlugin::class, $anotherDef->class);
        $this->assertSame('', $anotherDef->description);

        $extensionDef = $definitions['knowledge_tooling_example'];
        $this->assertInstanceOf(PluginDefinition::class, $extensionDef);
        $this->assertSame('knowledge_tooling_example', $extensionDef->id);
        $this->assertSame('Knowledge Tooling Example', $extensionDef->label);
        $this->assertSame(KnowledgeToolingExamplePlugin::class, $extensionDef->class);
        $this->assertStringContainsString('Reference extension plugin', $extensionDef->description);
    }

    public function testEmptyDirectory(): void
    {
        $emptyDir = sys_get_temp_dir() . '/waaseyaa_plugin_test_empty_' . uniqid();
        mkdir($emptyDir);

        try {
            $discovery = new AttributeDiscovery(
                directories: [$emptyDir],
                attributeClass: WaaseyaaPlugin::class,
            );

            $definitions = $discovery->getDefinitions();

            $this->assertSame([], $definitions);
        } finally {
            rmdir($emptyDir);
        }
    }

    public function testNonExistentDirectory(): void
    {
        $discovery = new AttributeDiscovery(
            directories: ['/tmp/nonexistent_waaseyaa_dir_' . uniqid()],
            attributeClass: WaaseyaaPlugin::class,
        );

        $definitions = $discovery->getDefinitions();

        $this->assertSame([], $definitions);
    }

    public function testDoesNotDiscoverClassesWithoutAttribute(): void
    {
        $discovery = new AttributeDiscovery(
            directories: [$this->fixturesDir],
            attributeClass: WaaseyaaPlugin::class,
        );

        $definitions = $discovery->getDefinitions();

        // NotAPlugin should not be discovered.
        foreach ($definitions as $definition) {
            $this->assertStringNotContainsString('NotAPlugin', $definition->class);
        }
    }

    public function testMultipleDirectories(): void
    {
        $emptyDir = sys_get_temp_dir() . '/waaseyaa_plugin_test_empty_' . uniqid();
        mkdir($emptyDir);

        try {
            $discovery = new AttributeDiscovery(
                directories: [$this->fixturesDir, $emptyDir],
                attributeClass: WaaseyaaPlugin::class,
            );

            $definitions = $discovery->getDefinitions();

            $this->assertCount(3, $definitions);
            $this->assertArrayHasKey('test_plugin', $definitions);
            $this->assertArrayHasKey('another_plugin', $definitions);
            $this->assertArrayHasKey('knowledge_tooling_example', $definitions);
        } finally {
            rmdir($emptyDir);
        }
    }

    /**
     * Regression test: a scanned class whose parent is a dev-only dependency
     * absent in production throws a fatal \Error (not \ReflectionException) when
     * PHP's autoloader tries to load it. processClass() must catch \Throwable
     * and skip the class rather than crashing the entire plugin discovery.
     *
     * The broken fixture is written to a per-test temp dir (not tests/Fixtures/)
     * so it does NOT affect the exact count asserted by testDiscoverPlugins().
     */
    public function testSkipsClassThatFatalsOnAutoload(): void
    {
        $tmpDir = sys_get_temp_dir() . '/waaseyaa_plugin_' . uniqid();
        mkdir($tmpDir, 0o755, true);

        $uniqueId = str_replace('.', '', uniqid('', true));
        $namespace = 'Waaseyaa\\Plugin\\Tests\\TmpAutoload\\N' . $uniqueId;
        $brokenFqcn = $namespace . '\\BrokenPlugin';
        $validFqcn = $namespace . '\\ValidPlugin';

        $brokenFile = $tmpDir . '/BrokenPlugin.php';
        $validFile = $tmpDir . '/ValidPlugin.php';

        // Class with a missing dev-only parent — autoloading this file throws
        // \Error: Class "Definitely\Missing\NonexistentParentClass" not found.
        file_put_contents($brokenFile, sprintf(
            "<?php\ndeclare(strict_types=1);\nnamespace %s;\nuse Waaseyaa\\Plugin\\Attribute\\WaaseyaaPlugin;\n#[WaaseyaaPlugin(id: 'broken_plugin', label: 'Broken Plugin')]\nfinal class BrokenPlugin extends \\Definitely\\Missing\\NonexistentParentClass {}\n",
            $namespace,
        ));

        // Valid class in the same directory — must still be discovered.
        file_put_contents($validFile, sprintf(
            "<?php\ndeclare(strict_types=1);\nnamespace %s;\nuse Waaseyaa\\Plugin\\Attribute\\WaaseyaaPlugin;\n#[WaaseyaaPlugin(id: 'valid_plugin', label: 'Valid Plugin')]\nfinal class ValidPlugin {}\n",
            $namespace,
        ));

        // Temporary autoloader: when class_exists() triggers PHP's autoload chain
        // for BrokenPlugin, this closure requires the file. PHP then tries to resolve
        // the parent class, fails, and throws \Error — reproducing the production crash.
        $autoloader = static function (string $class) use ($brokenFqcn, $validFqcn, $brokenFile, $validFile): void {
            if ($class === $brokenFqcn) {
                require $brokenFile;
            } elseif ($class === $validFqcn) {
                require $validFile;
            }
        };
        spl_autoload_register($autoloader);

        try {
            $discovery = new AttributeDiscovery([$tmpDir], WaaseyaaPlugin::class);
            $definitions = $discovery->getDefinitions();

            $this->assertArrayNotHasKey('broken_plugin', $definitions, 'Class with missing dev-only parent must be silently skipped.');
            $this->assertArrayHasKey('valid_plugin', $definitions, 'Valid plugin in the same directory must still be discovered alongside a broken class.');
        } finally {
            spl_autoload_unregister($autoloader);
            if (file_exists($brokenFile)) {
                unlink($brokenFile);
            }
            if (file_exists($validFile)) {
                unlink($validFile);
            }
            if (is_dir($tmpDir)) {
                rmdir($tmpDir);
            }
        }
    }
}
