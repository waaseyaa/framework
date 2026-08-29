<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\FreshInstall;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * `site:doctor` is a read-only filesystem diagnostic, and the generated
 * verification command runs it first. Before #2644 it went through ordinary CLI
 * boot, which reaches `AbstractKernel::bootDatabase()` before every
 * restricted-discovery guard, so verifying an uninitialized project created
 * `storage/waaseyaa.sqlite` plus its `-wal`/`-shm` sidecars as a side effect —
 * and then reported a misleading "apply the CFG-02 activation migration"
 * remediation instead of the missing site contract.
 *
 * A file created that way is a zero-table database, which `db:init` refuses as
 * "not Waaseyaa-initialized", so the diagnostic manufactured the very partial
 * bootstrap state it was supposed to detect.
 */
#[CoversNothing]
final class SiteDoctorIsReadOnlyTest extends TestCase
{
    private string $projectRoot = '';

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_doctor_readonly_' . bin2hex(random_bytes(6));
        mkdir($this->projectRoot, 0o700, true);
    }

    protected function tearDown(): void
    {
        if ($this->projectRoot === '' || !is_dir($this->projectRoot)) {
            return;
        }
        foreach ((array) glob($this->projectRoot . '/*') as $entry) {
            if (is_string($entry) && is_file($entry)) {
                unlink($entry);
            }
        }
        rmdir($this->projectRoot);
    }

    #[Test]
    public function strictDoctorOnAnUninitializedProjectCreatesNoDatabase(): void
    {
        $repoRoot = (string) realpath(__DIR__ . '/../../..');
        $databasePath = $this->projectRoot . '/probe.sqlite';

        $process = new Process(
            [PHP_BINARY, $repoRoot . '/packages/cli/bin/waaseyaa', 'site:doctor', '--strict', '--format=json', '--project-root=' . $this->projectRoot],
            $repoRoot,
            ['APP_ENV' => 'local', 'WAASEYAA_DB' => $databasePath] + getenv(),
        );
        $process->run();

        self::assertFileDoesNotExist($databasePath, 'site:doctor must not create the application database.');
        self::assertFileDoesNotExist($databasePath . '-wal', 'site:doctor must not create a SQLite write-ahead log.');
        self::assertFileDoesNotExist($databasePath . '-shm', 'site:doctor must not create a SQLite shared-memory index.');

        // The diagnosis must also name the real defect. Booting produced
        // "Active configuration generation is unavailable" — accurate about the
        // database it had just created, and useless about the missing contract.
        self::assertStringContainsString(
            '.waaseyaa/site.yaml',
            $process->getOutput() . $process->getErrorOutput(),
            'site:doctor must report the missing site contract, not a configuration-generation failure.',
        );
    }
}
