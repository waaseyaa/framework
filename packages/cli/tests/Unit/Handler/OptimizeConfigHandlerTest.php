<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Handler\OptimizeConfigHandler;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Config\Cache\ConfigCacheCompiler;
use Waaseyaa\Config\Storage\MemoryStorage;

#[CoversClass(OptimizeConfigHandler::class)]
final class OptimizeConfigHandlerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_optimize_config_handler_' . uniqid();
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    #[Test]
    public function compiles_config_and_reports_count(): void
    {
        $storage = new MemoryStorage();
        $storage->write('system.site', ['name' => 'Test']);
        $storage->write('user.settings', ['register' => 'admin']);

        $cachePath = $this->tempDir . '/config.php';
        $compiler = new ConfigCacheCompiler($storage, $cachePath);

        $tester = $this->createTester($compiler);
        $tester->execute([]);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('2 config objects', $tester->getStdout());
        $this->assertFileExists($cachePath);
    }

    private function createTester(ConfigCacheCompiler $compiler): CliTester
    {
        $handler = new OptimizeConfigHandler($compiler);
        $definition = new HandlerCommand(
            name: 'optimize:config',
            description: 'Compile and cache all configuration',
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
