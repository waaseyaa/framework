<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\FreshInstall;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

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
        // proc_open drained stdout to EOF before touching stderr, so a CLI run
        // that filled the ~64KB stderr buffer wedged both sides (#2491).
        //
        // The old command string was a shell hop whose only shell features were
        // escapeshellarg'd tokens and two `VAR=value` prefixes. The array form
        // below is exactly equivalent: bin/waaseyaa is an executable shebang
        // script, and a `VAR=value` command prefix ADDS to the inherited
        // environment, which is precisely what Symfony Process does with an env
        // array (it merges onto the inherited environment). proc_open passed no
        // env argument, so nothing here is a replacement — do not use
        // ReplacesProcessEnvironment. cwd is preserved; stdin was opened and
        // immediately closed without a write, so $input null is equivalent;
        // timeout null preserves the previous absence of any time bound.
        $projectRoot = dirname(__DIR__, 3);

        $process = new Process(
            [$projectRoot . '/bin/waaseyaa', ...$arguments],
            $projectRoot,
            ['APP_ENV' => 'testing', 'WAASEYAA_DB' => $database],
            null,
            null,
        );
        $exit = $process->run();

        return ['exit' => $exit, 'stdout' => $process->getOutput(), 'stderr' => $process->getErrorOutput()];
    }
}
