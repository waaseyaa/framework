<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the CLI entry point (bin/waaseyaa).
 *
 * These shell out to the actual bin so we cover the getcwd()-based
 * project-root resolution and the early-exit error paths verbatim.
 * See ADR-005.
 */
final class BinScriptTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa-bin-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    #[Test]
    public function errors_when_cwd_has_no_composer_json(): void
    {
        // tempDir is a scratch directory with no composer.json.
        $result = $this->runBin($this->tempDir);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('must be run from a project root', $result['stderr']);
        self::assertStringContainsString($this->normalizePath($this->tempDir), $this->normalizePath($result['stderr']));
    }

    #[Test]
    public function errors_when_composer_json_exists_but_no_vendor(): void
    {
        file_put_contents($this->tempDir . '/composer.json', '{"name": "test/fixture"}');
        // Deliberately no vendor/ directory.

        $result = $this->runBin($this->tempDir);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString("Run 'composer install'", $result['stderr']);
        self::assertStringContainsString($this->normalizePath($this->tempDir), $this->normalizePath($result['stderr']));
    }

    #[Test]
    public function resolves_project_root_from_cwd_despite_symlinked_cli_package(): void
    {
        // Mirror the consumer-with-symlinked-vendor pattern that broke the
        // old __DIR__-based resolution. Fixture has composer.json but no
        // vendor/autoload.php. vendor/waaseyaa/cli is symlinked to the real
        // packages/cli. If the bin resolves project root via __DIR__ through
        // the symlink, it would find the framework's own vendor/autoload.php
        // and NOT hit our "vendor/autoload.php not found" guard. With the
        // getcwd()-based resolution, it correctly looks in the fixture dir
        // and errors.
        file_put_contents($this->tempDir . '/composer.json', '{"name": "test/consumer-fixture"}');
        mkdir($this->tempDir . '/vendor/waaseyaa', 0755, true);
        if (!@symlink(dirname(__DIR__, 2), $this->tempDir . '/vendor/waaseyaa/cli')) {
            self::markTestSkipped('Symlink creation is not permitted in this environment.');
        }

        $symlinkedBin = $this->tempDir . '/vendor/waaseyaa/cli/bin/waaseyaa';
        self::assertFileExists($symlinkedBin, 'fixture symlink should expose the real bin');

        $result = $this->runBin($this->tempDir, $symlinkedBin);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString("Run 'composer install'", $result['stderr']);
        self::assertStringContainsString($this->normalizePath($this->tempDir), $this->normalizePath($result['stderr']));
        // Proves the bin looked in the fixture, not in the symlink target.
        self::assertStringNotContainsString(dirname(__DIR__, 3) . '/vendor/autoload.php', $result['stderr']);
    }

    /**
     * Run the bin with a given cwd.
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runBin(string $cwd, ?string $binPath = null): array
    {
        $bin = $binPath ?? self::canonicalBinPath();
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bin);

        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorspec, $pipes, $cwd);
        if (!is_resource($proc)) {
            self::fail('proc_open failed for: ' . $cmd);
        }

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private static function canonicalBinPath(): string
    {
        return dirname(__DIR__, 2) . '/bin/waaseyaa';
    }

    private function normalizePath(string $value): string
    {
        return str_replace('\\', '/', $value);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
