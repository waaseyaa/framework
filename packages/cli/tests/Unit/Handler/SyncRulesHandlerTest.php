<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Handler\SyncRulesHandler;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Testing\CliTester;

#[CoversClass(SyncRulesHandler::class)]
final class SyncRulesHandlerTest extends TestCase
{
    private string $sourceDir;
    private string $targetDir;

    protected function setUp(): void
    {
        $this->sourceDir = sys_get_temp_dir() . '/sync-rules-source-' . uniqid();
        $this->targetDir = sys_get_temp_dir() . '/sync-rules-target-' . uniqid();
        mkdir($this->sourceDir, 0755, true);
        mkdir($this->targetDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->sourceDir);
        $this->removeDir($this->targetDir);
    }

    private function makeDefinition(SyncRulesHandler $handler): HandlerCommand
    {
        return new HandlerCommand(
            name: 'sync-rules',
            description: 'Sync framework rules from Waaseyaa to this app',
            options: [
                new HandlerOption(name: 'force', shortcut: 'f', mode: HandlerOptionMode::None, description: 'Overwrite changed files without confirmation'),
                new HandlerOption(name: 'dry-run', mode: HandlerOptionMode::None, description: 'Show what would change without writing'),
            ],
            handler: \Closure::fromCallable([$handler, 'execute']),
        );
    }

    private function makeContainer(): \Psr\Container\ContainerInterface
    {
        return new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException('Container::get not used in unit tests');
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
    }

    #[Test]
    public function it_copies_new_rule_files(): void
    {
        file_put_contents($this->sourceDir . '/waaseyaa-framework.md', '# Framework');

        $handler = new SyncRulesHandler($this->sourceDir, $this->targetDir);
        $tester = CliTester::for($this->makeDefinition($handler), $this->makeContainer());
        $tester->executeMap(['--force' => true]);

        self::assertFileExists($this->targetDir . '/waaseyaa-framework.md');
        self::assertStringContainsString('Added', $tester->getStdout());
        self::assertSame(0, $tester->getExitCode());
    }

    #[Test]
    public function it_skips_unchanged_files(): void
    {
        $content = '# Framework';
        file_put_contents($this->sourceDir . '/waaseyaa-framework.md', $content);
        file_put_contents($this->targetDir . '/waaseyaa-framework.md', $content);

        $handler = new SyncRulesHandler($this->sourceDir, $this->targetDir);
        $tester = CliTester::for($this->makeDefinition($handler), $this->makeContainer());
        $tester->executeMap(['--force' => true]);

        self::assertStringContainsString('0 updated', $tester->getStdout());
    }

    #[Test]
    public function it_overwrites_changed_files_with_force(): void
    {
        file_put_contents($this->sourceDir . '/waaseyaa-framework.md', '# Updated');
        file_put_contents($this->targetDir . '/waaseyaa-framework.md', '# Old');

        $handler = new SyncRulesHandler($this->sourceDir, $this->targetDir);
        $tester = CliTester::for($this->makeDefinition($handler), $this->makeContainer());
        $tester->executeMap(['--force' => true]);

        self::assertStringContainsString('Updated', $tester->getStdout());
        self::assertSame('# Updated', file_get_contents($this->targetDir . '/waaseyaa-framework.md'));
    }

    #[Test]
    public function it_never_touches_non_waaseyaa_files(): void
    {
        file_put_contents($this->targetDir . '/app-specific-rule.md', '# Mine');

        $handler = new SyncRulesHandler($this->sourceDir, $this->targetDir);
        $tester = CliTester::for($this->makeDefinition($handler), $this->makeContainer());
        $tester->executeMap(['--force' => true]);

        self::assertFileExists($this->targetDir . '/app-specific-rule.md');
        self::assertSame('# Mine', file_get_contents($this->targetDir . '/app-specific-rule.md'));
    }

    #[Test]
    public function it_creates_target_directory_if_missing(): void
    {
        $this->removeDir($this->targetDir);
        file_put_contents($this->sourceDir . '/waaseyaa-framework.md', '# Framework');

        $handler = new SyncRulesHandler($this->sourceDir, $this->targetDir);
        $tester = CliTester::for($this->makeDefinition($handler), $this->makeContainer());
        $tester->executeMap(['--force' => true]);

        self::assertFileExists($this->targetDir . '/waaseyaa-framework.md');
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = glob($dir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                is_dir($file) ? $this->removeDir($file) : unlink($file);
            }
        }
        rmdir($dir);
    }
}
