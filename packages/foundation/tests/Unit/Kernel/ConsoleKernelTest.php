<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Provider\MaintenanceServiceProvider;
use Waaseyaa\Foundation\Kernel\ConsoleKernel;
use Waaseyaa\Tests\Support\ProcessFieldReadRuntime;

/**
 * ConsoleKernel::handle() is a thin wrapper that delegates to the Symfony Console application.
 *
 * The full CLI behaviour (command dispatch, exit codes) is covered by the
 * Symfony Console integration tests. These tests verify only that the kernel
 * correctly forwards argv and projectRoot, and that the known exit-code
 * semantics hold end-to-end against the real project.
 */
#[CoversClass(ConsoleKernel::class)]
#[CoversClass(MaintenanceServiceProvider::class)]
final class ConsoleKernelTest extends TestCase
{
    /** @var list<string> */
    private array $originalArgv;

    private string|false $originalAppEnv;

    private string|false $originalDatabase;

    private string|false $originalMaintenanceFlag;

    protected function setUp(): void
    {
        $this->originalArgv = $_SERVER['argv'] ?? [];
        $this->originalAppEnv = getenv('APP_ENV');
        $this->originalDatabase = getenv('WAASEYAA_DB');
        $this->originalMaintenanceFlag = getenv('WAASEYAA_MAINTENANCE_FLAG');
    }

    protected function tearDown(): void
    {
        ProcessFieldReadRuntime::reset();
        $_SERVER['argv'] = $this->originalArgv;
        $this->restoreEnvironment('APP_ENV', $this->originalAppEnv);
        $this->restoreEnvironment('WAASEYAA_DB', $this->originalDatabase);
        $this->restoreEnvironment('WAASEYAA_MAINTENANCE_FLAG', $this->originalMaintenanceFlag);
    }

    #[Test]
    public function handle_returns_non_zero_for_unknown_command(): void
    {
        $projectRoot = dirname(__DIR__, 6); // repo root in the worktree
        $_SERVER['argv'] = ['waaseyaa', 'not-a-real-command'];

        $kernel = new ConsoleKernel($projectRoot);

        ob_start();
        $exitCode = $kernel->handle();
        ob_get_clean();

        $this->assertNotSame(0, $exitCode);
    }

    #[Test]
    public function handle_returns_zero_when_no_command_given(): void
    {
        $projectRoot = dirname(__DIR__, 6); // repo root in the worktree
        // Symfony runtime shows the short no-command hint when no command is supplied.
        $_SERVER['argv'] = ['waaseyaa'];

        $kernel = new ConsoleKernel($projectRoot);

        ob_start();
        $exitCode = $kernel->handle();
        ob_get_clean();

        $this->assertSame(0, $exitCode);
    }

    #[Test]
    public function dbInitDryRunCanInspectAMissingProductionDatabaseWithoutBootingIt(): void
    {
        $projectRoot = dirname(__DIR__, 6);
        $database = sys_get_temp_dir() . '/waaseyaa-db-init-' . bin2hex(random_bytes(8)) . '.sqlite';
        $_SERVER['argv'] = ['waaseyaa', 'db:init', '--dry-run'];
        putenv('APP_ENV=production');
        putenv('WAASEYAA_DB=' . $database);

        ob_start();
        $exitCode = (new ConsoleKernel($projectRoot))->handle();
        $output = (string) ob_get_clean();

        self::assertSame(0, $exitCode, $output);
        self::assertFileDoesNotExist($database);
    }

    /**
     * #2644: the generated verification command runs `site:doctor --strict`
     * first, so a booting doctor made verification a write. Ordinary CLI boot
     * reaches `AbstractKernel::bootDatabase()` before every restricted-discovery
     * guard, and the zero-table file it left behind is precisely what `db:init`
     * later refuses as "not Waaseyaa-initialized".
     */
    #[Test]
    public function siteDoctorDiagnosesWithoutBootingOrCreatingTheDatabase(): void
    {
        $projectRoot = dirname(__DIR__, 6);
        $database = sys_get_temp_dir() . '/waaseyaa-site-doctor-' . bin2hex(random_bytes(8)) . '.sqlite';
        $fixture = sys_get_temp_dir() . '/waaseyaa-site-doctor-root-' . bin2hex(random_bytes(8));
        mkdir($fixture, 0o700, true);
        $_SERVER['argv'] = ['waaseyaa', 'site:doctor', '--strict', '--format=json', '--project-root=' . $fixture];
        putenv('APP_ENV=local');
        putenv('WAASEYAA_DB=' . $database);

        try {
            ob_start();
            (new ConsoleKernel($projectRoot))->handle();
            ob_get_clean();

            self::assertFileDoesNotExist($database);
            self::assertFileDoesNotExist($database . '-wal');
            self::assertFileDoesNotExist($database . '-shm');
        } finally {
            rmdir($fixture);
        }
    }

    #[Test]
    public function maintenanceCommandsRemainAvailableWhenProductionBootIsBlocked(): void
    {
        $projectRoot = dirname(__DIR__, 6);
        $database = sys_get_temp_dir() . '/waaseyaa-maintenance-missing-' . bin2hex(random_bytes(8)) . '.sqlite';
        $flag = sys_get_temp_dir() . '/waaseyaa-maintenance-' . bin2hex(random_bytes(8)) . '.json';
        putenv('APP_ENV=production');
        putenv('WAASEYAA_DB=' . $database);
        putenv('WAASEYAA_MAINTENANCE_FLAG=' . $flag);

        try {
            $_SERVER['argv'] = ['waaseyaa', 'maintenance:on', '--retry-after=75', '--message=database transition'];
            $onExit = (new ConsoleKernel($projectRoot))->handle();

            self::assertSame(0, $onExit);
            self::assertFileDoesNotExist($database);
            $state = json_decode((string) file_get_contents($flag), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(true, $state['active'] ?? null);
            self::assertSame(75, $state['retry_after'] ?? null);

            $_SERVER['argv'] = ['waaseyaa', 'maintenance:status', '--json'];
            $statusExit = (new ConsoleKernel($projectRoot))->handle();

            self::assertSame(1, $statusExit);
            self::assertFileDoesNotExist($database);

            $_SERVER['argv'] = ['waaseyaa', 'maintenance:off'];
            $offExit = (new ConsoleKernel($projectRoot))->handle();

            self::assertSame(0, $offExit);
            self::assertFileDoesNotExist($flag);
            self::assertFileDoesNotExist($database);
        } finally {
            if (is_file($flag)) {
                unlink($flag);
            }
        }
    }

    private function restoreEnvironment(string $name, string|false $value): void
    {
        putenv($value === false ? $name : $name . '=' . $value);
    }
}
