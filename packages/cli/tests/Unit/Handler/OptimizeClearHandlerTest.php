<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Handler\OptimizeClearHandler;
use Waaseyaa\CLI\Testing\CliTester;

#[CoversClass(OptimizeClearHandler::class)]
final class OptimizeClearHandlerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_clear_handler_test_' . uniqid();
        mkdir($this->tempDir . '/framework', 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    #[Test]
    public function clears_cached_php_files(): void
    {
        file_put_contents($this->tempDir . '/framework/packages.php', '<?php return [];');
        file_put_contents($this->tempDir . '/framework/config.php', '<?php return [];');

        $tester = $this->createTester($this->tempDir);
        $tester->execute([]);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('2 cached artifact(s) cleared', $tester->getStdout());
        $this->assertFileDoesNotExist($this->tempDir . '/framework/packages.php');
        $this->assertFileDoesNotExist($this->tempDir . '/framework/config.php');
    }

    #[Test]
    public function reports_no_artifacts_when_empty(): void
    {
        $tester = $this->createTester($this->tempDir);
        $tester->execute([]);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('No cached artifacts found', $tester->getStdout());
    }

    #[Test]
    public function reports_no_artifacts_when_no_framework_dir(): void
    {
        $noFramework = sys_get_temp_dir() . '/waaseyaa_clear_nofw_handler_' . uniqid();
        mkdir($noFramework, 0o755, true);

        $tester = $this->createTester($noFramework);
        $tester->execute([]);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('No cached artifacts found', $tester->getStdout());

        rmdir($noFramework);
    }

    private function createTester(string $storagePath): CliTester
    {
        $handler = new OptimizeClearHandler(storagePath: $storagePath);
        $definition = new HandlerCommand(
            name: 'optimize:clear',
            description: 'Remove all cached optimization artifacts',
            handler: \Closure::fromCallable([$handler, 'execute']),
        );

        $container = new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed { throw new \RuntimeException("Not found: $id"); }
            public function has(string $id): bool { return false; }
        };

        return CliTester::for($definition, $container);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
