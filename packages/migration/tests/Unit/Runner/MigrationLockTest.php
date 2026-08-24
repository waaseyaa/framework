<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Unit\Runner;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Waaseyaa\Migration\Exception\MigrationConcurrencyException;
use Waaseyaa\Migration\Runner\MigrationLock;

#[CoversClass(MigrationLock::class)]
final class MigrationLockTest extends TestCase
{
    private string $lockDir = '';

    protected function setUp(): void
    {
        $this->lockDir = \sys_get_temp_dir()
            . \DIRECTORY_SEPARATOR
            . 'waaseyaa_lock_test_'
            . \uniqid('', true);
    }

    protected function tearDown(): void
    {
        if ($this->lockDir !== '' && \is_dir($this->lockDir)) {
            foreach (\glob($this->lockDir . '/*.lock') ?: [] as $file) {
                @\unlink($file);
            }
            @\rmdir($this->lockDir);
        }
    }

    #[Test]
    public function constructor_rejects_empty_migration_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MigrationLock(migrationId: '', lockDir: $this->lockDir);
    }

    #[Test]
    public function constructor_rejects_malformed_migration_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MigrationLock(migrationId: 'Bad-Id', lockDir: $this->lockDir);
    }

    #[Test]
    public function constructor_rejects_empty_lock_dir(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MigrationLock(migrationId: 'demo', lockDir: '');
    }

    #[Test]
    public function lock_path_combines_dir_and_id(): void
    {
        $lock = new MigrationLock(migrationId: 'demo', lockDir: $this->lockDir);

        self::assertSame(
            $this->lockDir . \DIRECTORY_SEPARATOR . 'demo.lock',
            $lock->lockPath(),
        );
    }

    #[Test]
    public function acquire_creates_lock_dir_and_writes_pid(): void
    {
        self::assertDirectoryDoesNotExist($this->lockDir);

        $lock = new MigrationLock(migrationId: 'demo', lockDir: $this->lockDir);
        $lock->acquire();

        try {
            self::assertDirectoryExists($this->lockDir);
            self::assertFileExists($lock->lockPath());
            self::assertSame(\getmypid(), $lock->pid());
        } finally {
            $lock->release();
        }
    }

    #[Test]
    public function release_leaves_the_lock_file_in_place_for_pid_inspection(): void
    {
        // Issue #1450 — release() intentionally does NOT unlink the lock
        // file. Removing it would introduce a TOCTOU window between unlink
        // and the next acquirer's fopen()+flock(). The PID body is the
        // operator-facing inspection surface; it is overwritten on the
        // next successful acquire().
        $lock = new MigrationLock(migrationId: 'demo', lockDir: $this->lockDir);
        $lock->acquire();
        self::assertFileExists($lock->lockPath());

        $lock->release();
        self::assertFileExists(
            $lock->lockPath(),
            'release() must leave the lock file on disk so operators can inspect the previous holder PID.',
        );
    }

    #[Test]
    public function release_is_idempotent(): void
    {
        $lock = new MigrationLock(migrationId: 'demo', lockDir: $this->lockDir);
        $lock->acquire();
        $lock->release();
        $lock->release(); // No throw.

        // Lock file persists; release() is idempotent on the OS lock and
        // does not delete the file (issue #1450).
        self::assertFileExists($lock->lockPath());
    }

    #[Test]
    public function acquire_is_idempotent_within_one_instance(): void
    {
        $lock = new MigrationLock(migrationId: 'demo', lockDir: $this->lockDir);
        $lock->acquire();
        $lock->acquire(); // No throw.

        try {
            self::assertFileExists($lock->lockPath());
        } finally {
            $lock->release();
        }
    }

    #[Test]
    public function reacquire_after_release_writes_fresh_pid(): void
    {
        $lock = new MigrationLock(migrationId: 'demo', lockDir: $this->lockDir);

        $lock->acquire();
        $lock->release();
        $lock->acquire();

        try {
            self::assertSame(\getmypid(), $lock->pid());
        } finally {
            $lock->release();
        }
    }

    #[Test]
    public function pid_returns_null_when_no_lock_file_exists(): void
    {
        $lock = new MigrationLock(migrationId: 'demo', lockDir: $this->lockDir);

        self::assertNull($lock->pid());
    }

    #[Test]
    public function pid_returns_null_for_non_numeric_body(): void
    {
        \mkdir($this->lockDir, 0o755, true);
        $lock = new MigrationLock(migrationId: 'demo', lockDir: $this->lockDir);
        \file_put_contents($lock->lockPath(), "not-a-pid\n");

        self::assertNull($lock->pid());
    }

    #[Test]
    public function contention_within_same_process_via_subprocess_raises_concurrency_exception(): void
    {
        // Within one PHP process, the same flock holder succeeds on
        // re-acquire (kernel records the lock per file descriptor).
        // To exercise actual contention we shell a child PHP process.
        if (!\function_exists('proc_open')) {
            self::markTestSkipped('proc_open() not available; cannot test cross-process contention.');
        }

        $holder = new MigrationLock(migrationId: 'demo', lockDir: $this->lockDir);
        $holder->acquire();

        try {
            $script = $this->childAcquireScript('demo', $this->lockDir);
            $childExit = $this->runChildScript($script);
            self::assertSame(
                10,
                $childExit,
                'Child should exit 10 to signal MigrationConcurrencyException was raised.',
            );
        } finally {
            $holder->release();
        }
    }

    /**
     * Compose a tiny PHP source string the child process runs. It tries
     * to acquire the same lock and exits with code 10 when the typed
     * exception is raised; any other outcome exits non-10 so the parent
     * test fails loudly.
     */
    private function childAcquireScript(string $migrationId, string $lockDir): string
    {
        // Resolve the repo's autoloader: tests/Unit/Runner → up six levels
        // reaches the lane worktree root where vendor/ lives.
        // tests/Unit/Runner -> tests/Unit -> tests -> migration -> packages
        // -> repo root.
        $autoloader = \dirname(__DIR__, 5) . '/vendor/autoload.php';
        if (!\is_file($autoloader)) {
            self::markTestSkipped('vendor/autoload.php not found at ' . $autoloader);
        }
        $autoloaderEscaped = \addslashes($autoloader);
        $migEscaped = \addslashes($migrationId);
        $dirEscaped = \addslashes($lockDir);

        return <<<PHP
<?php
require '{$autoloaderEscaped}';
try {
    \$lock = new \\Waaseyaa\\Migration\\Runner\\MigrationLock('{$migEscaped}', '{$dirEscaped}');
    \$lock->acquire();
    exit(99); // Should not happen — parent holds the lock.
} catch (\\Waaseyaa\\Migration\\Exception\\MigrationConcurrencyException \$e) {
    // Verify the message carries the parent's PID + the lock path.
    if (!str_contains(\$e->getMessage(), 'is already running')) {
        exit(98);
    }
    exit(10);
} catch (\\Throwable \$e) {
    fwrite(STDERR, 'unexpected: ' . get_class(\$e) . ': ' . \$e->getMessage());
    exit(97);
}
PHP;
    }

    /**
     * Run a PHP source-code string in a child process and return its exit code.
     */
    private function runChildScript(string $source): int
    {
        // The child PHP source arrives on stdin, which proc_open wrote and then
        // closed; Symfony Process takes it as the constructor's $input and does
        // the same. env null matches the omitted proc_open argument, and timeout
        // null keeps the previous absence of any time bound — this test
        // deliberately holds a contended lock while the child tries to acquire it.
        $process = new Process(
            [\PHP_BINARY, '-d', 'error_reporting=E_ALL'],
            null,
            null,
            $source,
            null,
        );
        $exitCode = $process->run();
        $stderr = $process->getErrorOutput();

        // Surface stderr to PHPUnit on failure paths for debuggability.
        if ($exitCode !== 10 && $stderr !== '') {
            \fwrite(\STDERR, "[child stderr] {$stderr}\n");
        }

        return $exitCode;
    }
}
