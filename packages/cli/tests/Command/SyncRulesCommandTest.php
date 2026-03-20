<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Waaseyaa\CLI\Command\SyncRulesCommand;

final class SyncRulesCommandTest extends TestCase
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

    #[Test]
    public function it_copies_new_rule_files(): void
    {
        file_put_contents($this->sourceDir . '/waaseyaa-framework.md', '# Framework');

        $tester = $this->runCommand(['--force' => true]);

        $this->assertFileExists($this->targetDir . '/waaseyaa-framework.md');
        $this->assertStringContainsString('Added', $tester->getDisplay());
        $this->assertSame(0, $tester->getStatusCode());
    }

    #[Test]
    public function it_skips_unchanged_files(): void
    {
        $content = '# Framework';
        file_put_contents($this->sourceDir . '/waaseyaa-framework.md', $content);
        file_put_contents($this->targetDir . '/waaseyaa-framework.md', $content);

        $tester = $this->runCommand(['--force' => true]);

        $this->assertStringContainsString('0 updated', $tester->getDisplay());
    }

    #[Test]
    public function it_overwrites_changed_files_with_force(): void
    {
        file_put_contents($this->sourceDir . '/waaseyaa-framework.md', '# Updated');
        file_put_contents($this->targetDir . '/waaseyaa-framework.md', '# Old');

        $tester = $this->runCommand(['--force' => true]);

        $this->assertStringContainsString('Updated', $tester->getDisplay());
        $this->assertSame('# Updated', file_get_contents($this->targetDir . '/waaseyaa-framework.md'));
    }

    #[Test]
    public function it_never_touches_non_waaseyaa_files(): void
    {
        file_put_contents($this->targetDir . '/app-specific-rule.md', '# Mine');

        $tester = $this->runCommand(['--force' => true]);

        $this->assertFileExists($this->targetDir . '/app-specific-rule.md');
        $this->assertSame('# Mine', file_get_contents($this->targetDir . '/app-specific-rule.md'));
    }

    #[Test]
    public function it_creates_target_directory_if_missing(): void
    {
        $this->removeDir($this->targetDir);
        file_put_contents($this->sourceDir . '/waaseyaa-framework.md', '# Framework');

        $tester = $this->runCommand(['--force' => true]);

        $this->assertFileExists($this->targetDir . '/waaseyaa-framework.md');
    }

    #[Test]
    public function it_reports_dry_run_without_writing(): void
    {
        file_put_contents($this->sourceDir . '/waaseyaa-framework.md', '# New');

        $tester = $this->runCommand(['--dry-run' => true]);

        $this->assertFileDoesNotExist($this->targetDir . '/waaseyaa-framework.md');
        $this->assertStringContainsString('dry run', strtolower($tester->getDisplay()));
    }

    #[Test]
    public function it_only_processes_waaseyaa_prefixed_files_from_source(): void
    {
        file_put_contents($this->sourceDir . '/waaseyaa-framework.md', '# Framework');
        file_put_contents($this->sourceDir . '/other-file.md', '# Other');

        $tester = $this->runCommand(['--force' => true]);

        $this->assertFileExists($this->targetDir . '/waaseyaa-framework.md');
        $this->assertFileDoesNotExist($this->targetDir . '/other-file.md');
    }

    private function runCommand(array $input = []): CommandTester
    {
        $command = new SyncRulesCommand($this->sourceDir, $this->targetDir);

        $app = new Application();
        $app->add($command);

        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') as $file) {
            is_dir($file) ? $this->removeDir($file) : unlink($file);
        }
        rmdir($dir);
    }
}
