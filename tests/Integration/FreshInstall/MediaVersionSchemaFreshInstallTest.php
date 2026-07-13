<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\FreshInstall;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reproduces #1980 through the operator-facing fresh-install commands.
 */
final class MediaVersionSchemaFreshInstallTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa-media-schema-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->tempDir);
    }

    #[Test]
    public function fresh_db_init_passes_schema_check_for_media_version(): void
    {
        $database = $this->tempDir . '/fresh.sqlite';

        $init = $this->runCli(['db:init'], $database);
        self::assertSame(0, $init['exit'], $init['stdout'] . $init['stderr']);

        $check = $this->runCli(['schema:check'], $database);
        self::assertSame(0, $check['exit'], $check['stdout'] . $check['stderr']);
        self::assertStringNotContainsString('media_version', $check['stdout']);
    }

    /** @return array{exit: int, stdout: string, stderr: string} */
    private function runCli(array $arguments, string $database): array
    {
        $projectRoot = dirname(__DIR__, 3);
        $command = sprintf(
            'APP_ENV=local WAASEYAA_DB=%s %s %s',
            escapeshellarg($database),
            escapeshellarg($projectRoot . '/bin/waaseyaa'),
            implode(' ', array_map('escapeshellarg', $arguments)),
        );
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $projectRoot,
        );
        self::assertIsResource($process);

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
