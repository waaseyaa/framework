<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Optimize;

use Waaseyaa\CLI\Command\Optimize\OptimizeConfigCommand;
use Waaseyaa\Config\Cache\ConfigCacheCompiler;
use Waaseyaa\Config\Storage\MemoryStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(OptimizeConfigCommand::class)]
final class OptimizeConfigCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_optimize_cmd_test_' . uniqid();
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

        $command = new OptimizeConfigCommand($compiler);
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('2 config objects', $tester->getDisplay());
        $this->assertFileExists($cachePath);
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
