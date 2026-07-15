<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Kernel\AbstractKernel;

#[CoversClass(AbstractKernel::class)]
final class AbstractKernelExtensionRunnerTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_kernel_extension_test_' . uniqid();
        mkdir($this->projectRoot . '/config', 0755, true);
        mkdir($this->projectRoot . '/storage', 0755, true);

        file_put_contents(
            $this->projectRoot . '/config/entity-types.php',
            "<?php\nreturn [\n    new \\Waaseyaa\\Entity\\EntityType(\n        id: 'test',\n        label: 'Test',\n        class: \\stdClass::class,\n        keys: ['id' => 'id'],\n    ),\n];",
        );
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectRoot, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->projectRoot);
    }

    #[Test]
    public function itBootsKnowledgeExtensionRunnerFromConfigDirectories(): void
    {
        $repoRoot = dirname(__DIR__, 5);
        $fixtures = $repoRoot . '/packages/plugin/tests/Fixtures';

        file_put_contents(
            $this->projectRoot . '/config/waaseyaa.php',
            "<?php return ['database' => ':memory:', 'environment' => 'testing', 'extensions' => ['plugin_directories' => ['" . addslashes($fixtures) . "']]];",
        );

        $kernel = new TestKernel($this->projectRoot);
        $kernel->bootPublic();

        $descriptors = $kernel->getKnowledgeToolingExtensionRunner()->describeExtensions();
        $this->assertCount(1, $descriptors);
        $this->assertSame('knowledge_tooling_example', $descriptors[0]['plugin_id']);

        $workflow = $kernel->applyWorkflowExtensionContext(['workflow_tags' => ['base']]);
        $this->assertSame(['knowledge_tooling_example'], $workflow['extension_trace']);
    }

    #[Test]
    public function itDefaultsToEmptyRunnerWhenNoDirectoriesConfigured(): void
    {
        file_put_contents(
            $this->projectRoot . '/config/waaseyaa.php',
            "<?php return ['database' => ':memory:', 'environment' => 'testing'];",
        );

        $kernel = new TestKernel($this->projectRoot);
        $kernel->bootPublic();

        $descriptors = $kernel->getKnowledgeToolingExtensionRunner()->describeExtensions();
        $this->assertSame([], $descriptors);
    }
}

final class TestKernel extends AbstractKernel
{
    public function bootPublic(): void
    {
        $this->boot();
    }
}
