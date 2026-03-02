<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Optimize;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Waaseyaa\CLI\Command\Optimize\OptimizeClearCommand;

#[CoversClass(OptimizeClearCommand::class)]
final class OptimizeClearCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_clear_test_' . uniqid();
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

        $command = new OptimizeClearCommand(storagePath: $this->tempDir);
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('2 cached artifact(s) cleared', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->tempDir . '/framework/packages.php');
        $this->assertFileDoesNotExist($this->tempDir . '/framework/config.php');
    }

    #[Test]
    public function reports_no_artifacts_when_empty(): void
    {
        // framework dir exists but is empty
        $command = new OptimizeClearCommand(storagePath: $this->tempDir);
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('No cached artifacts found', $tester->getDisplay());
    }

    #[Test]
    public function reports_no_artifacts_when_no_framework_dir(): void
    {
        $noFramework = sys_get_temp_dir() . '/waaseyaa_clear_nofw_' . uniqid();
        mkdir($noFramework, 0o755, true);

        $command = new OptimizeClearCommand(storagePath: $noFramework);
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('No cached artifacts found', $tester->getDisplay());

        rmdir($noFramework);
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
