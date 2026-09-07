<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\ProjectInit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\ProjectInit\ProcOpenProjectInitProcessRunner;
use Waaseyaa\CLI\ProjectInit\ProjectInitProcessResult;

#[CoversClass(ProcOpenProjectInitProcessRunner::class)]
final class ProcOpenProjectInitProcessRunnerTest extends TestCase
{
    #[Test]
    public function concurrentStdoutAndStderrAreDrainedInCapturedMode(): void
    {
        $code = <<<'PHP'
fwrite(STDOUT, str_repeat('o', 1000));
fflush(STDOUT);
fwrite(STDERR, str_repeat('e', 1000));
PHP;

        $result = new ProcOpenProjectInitProcessRunner()->run(
            command: [PHP_BINARY, '-r', $code],
            cwd: sys_get_temp_dir(),
            captureOutput: true,
        );

        self::assertSame(0, $result->exitCode);
        self::assertSame(1000, strlen($result->stdout));
        self::assertSame(1000, strlen($result->stderr));
        self::assertSame(str_repeat('o', 1000), $result->stdout);
        self::assertSame(str_repeat('e', 1000), $result->stderr);
    }

    #[Test]
    public function exactCapOutputIsAccepted(): void
    {
        $limit = 64;
        $result = new ProcOpenProjectInitProcessRunner(maxOutputBytesPerStream: $limit)->run(
            command: [PHP_BINARY, '-r', 'fwrite(STDOUT, str_repeat("x", $argv[1]));', (string) $limit],
            cwd: sys_get_temp_dir(),
            captureOutput: true,
        );

        self::assertSame(0, $result->exitCode);
        self::assertSame(str_repeat('x', $limit), $result->stdout);
        self::assertFalse($result->hasRunnerError());
    }

    #[Test]
    public function oneByteOverCapTerminatesWithOutputLimitError(): void
    {
        $limit = 64;
        $result = new ProcOpenProjectInitProcessRunner(maxOutputBytesPerStream: $limit)->run(
            command: [PHP_BINARY, '-r', 'fwrite(STDOUT, str_repeat("x", $argv[1]));', (string) ($limit + 1)],
            cwd: sys_get_temp_dir(),
            captureOutput: true,
        );

        self::assertTrue($result->hasRunnerError());
        self::assertSame(ProjectInitProcessResult::ERROR_CHILD_OUTPUT_LIMIT, $result->errorCode);
    }

    #[Test]
    public function windowsFileCaptureReportsPostSpawnUnlinkFailureAs007(): void
    {
        $unlinkAttempts = 0;
        $runner = new ProcOpenProjectInitProcessRunner(
            capturedFileTransport: true,
            captureUnlinker: static function (string $path) use (&$unlinkAttempts): bool {
                if (++$unlinkAttempts === 1) {
                    return false;
                }

                return @unlink($path);
            },
        );

        $workingDirectory = $this->createWorkingDirectory();
        try {
            $result = $runner->run(
                command: [PHP_BINARY, '-r', 'usleep(2_000_000);'],
                cwd: $workingDirectory,
                captureOutput: true,
            );
        } finally {
            @rmdir($workingDirectory);
        }

        self::assertSame(ProjectInitProcessResult::ERROR_CHILD_CAPTURE_FAILED, $result->errorCode);
    }

    #[Test]
    public function fileCaptureDrainsBothStreamsAtTheExactCapAndRemovesNames(): void
    {
        $paths = [];
        $runner = new ProcOpenProjectInitProcessRunner(
            maxOutputBytesPerStream: 64,
            capturedFileTransport: true,
            captureUnlinker: static function (string $path) use (&$paths): bool {
                $paths[] = $path;

                return @unlink($path);
            },
        );

        $workingDirectory = $this->createWorkingDirectory();
        try {
            $result = $runner->run(
                command: [PHP_BINARY, '-r', 'fwrite(STDOUT, str_repeat("o", 64)); fwrite(STDERR, str_repeat("e", 64));'],
                cwd: $workingDirectory,
                captureOutput: true,
            );
        } finally {
            @rmdir($workingDirectory);
        }

        self::assertSame(0, $result->exitCode);
        self::assertSame(str_repeat('o', 64), $result->stdout);
        self::assertSame(str_repeat('e', 64), $result->stderr);
        self::assertCount(2, $paths);
        self::assertFalse(is_file($paths[0]));
        self::assertFalse(is_file($paths[1]));
    }

    #[Test]
    public function fileCaptureDetectsOneByteOverCapAfterChildExit(): void
    {
        $workingDirectory = $this->createWorkingDirectory();
        try {
            $result = (new ProcOpenProjectInitProcessRunner(
                maxOutputBytesPerStream: 64,
                capturedFileTransport: true,
            ))->run(
                command: [PHP_BINARY, '-r', 'fwrite(STDOUT, str_repeat("x", 65));'],
                cwd: $workingDirectory,
                captureOutput: true,
            );
        } finally {
            @rmdir($workingDirectory);
        }

        self::assertSame(ProjectInitProcessResult::ERROR_CHILD_OUTPUT_LIMIT, $result->errorCode);
    }

    #[Test]
    public function fileCaptureKeepsTimeoutResponsiveWithoutPipeReads(): void
    {
        $workingDirectory = $this->createWorkingDirectory();
        $started = microtime(true);
        try {
            $result = (new ProcOpenProjectInitProcessRunner(
                maxRuntimeSeconds: 0.2,
                capturedFileTransport: true,
            ))->run(
                command: [PHP_BINARY, '-r', 'usleep(3_000_000);'],
                cwd: $workingDirectory,
                captureOutput: true,
            );
        } finally {
            @rmdir($workingDirectory);
        }

        self::assertSame(ProjectInitProcessResult::ERROR_CHILD_TIMEOUT, $result->errorCode);
        self::assertLessThan(1.0, microtime(true) - $started);
    }

    #[Test]
    public function cleanupFailureWinsAndPreservesInitiating007(): void
    {
        $unlinkAttempts = 0;
        $runner = new ProcOpenProjectInitProcessRunner(
            capturedFileTransport: true,
            captureUnlinker: static function (string $path) use (&$unlinkAttempts): bool {
                if (++$unlinkAttempts === 1) {
                    return false;
                }

                return @unlink($path);
            },
            terminalConfirmationProbe: static fn (): bool => false,
        );

        $workingDirectory = $this->createWorkingDirectory();
        try {
            $result = $runner->run(
                command: [PHP_BINARY, '-r', 'usleep(2_000_000);'],
                cwd: $workingDirectory,
                captureOutput: true,
            );
        } finally {
            @rmdir($workingDirectory);
        }

        self::assertSame(ProjectInitProcessResult::ERROR_CHILD_CLEANUP_FAILED, $result->errorCode);
        self::assertStringContainsString(ProjectInitProcessResult::ERROR_CHILD_CAPTURE_FAILED, (string) $result->diagnostic);
    }

    #[Test]
    public function stalledChildIsTerminatedAtRuntimeBound(): void
    {
        $started = microtime(true);
        $result = new ProcOpenProjectInitProcessRunner(maxRuntimeSeconds: 0.2)->run(
            command: [PHP_BINARY, '-r', 'while (true) {}'],
            cwd: sys_get_temp_dir(),
            captureOutput: true,
        );

        self::assertTrue($result->hasRunnerError());
        self::assertSame(ProjectInitProcessResult::ERROR_CHILD_TIMEOUT, $result->errorCode);
        self::assertLessThan(3.0, microtime(true) - $started);
    }

    #[Test]
    public function childExitCodeIsRecoveredAfterTermination(): void
    {
        $result = new ProcOpenProjectInitProcessRunner()->run(
            command: [PHP_BINARY, '-r', 'fwrite(STDOUT, "ok"); exit(42);'],
            cwd: sys_get_temp_dir(),
            captureOutput: true,
        );

        self::assertSame(42, $result->exitCode);
        self::assertSame('ok', $result->stdout);
    }

    #[Test]
    public function timeoutLeavesChildProcessTerminalOnLinux(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_kill')) {
            self::markTestSkipped('Linux posix_kill custody proof is host-specific.');
        }

        $pid = $this->runLongLivedChildAndReturnPid(new ProcOpenProjectInitProcessRunner(maxRuntimeSeconds: 0.2));
        self::assertFalse(@posix_kill($pid, 0), 'Child must be terminal before runner returns.');
    }

    #[Test]
    public function sigtermIgnoringChildIsHardKilledOnLinux(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_kill') || !extension_loaded('pcntl')) {
            self::markTestSkipped('Linux pcntl hard-kill custody proof is host-specific.');
        }

        $child = <<<'PHP'
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, SIG_IGN);
}
file_put_contents($argv[1], (string) getmypid());
while (true) { usleep(100_000); }
PHP;

        $pidFile = tempnam(sys_get_temp_dir(), 'waaseyaa_project_init_sigterm_');
        self::assertIsString($pidFile);
        $result = new ProcOpenProjectInitProcessRunner(maxRuntimeSeconds: 0.2)->run(
            command: [PHP_BINARY, '-r', $child, $pidFile],
            cwd: sys_get_temp_dir(),
            captureOutput: true,
        );

        self::assertTrue($result->hasRunnerError());
        self::assertSame(ProjectInitProcessResult::ERROR_CHILD_TIMEOUT, $result->errorCode);
        $pid = (int) file_get_contents($pidFile);
        @unlink($pidFile);
        self::assertGreaterThan(0, $pid);
        self::assertFalse(@posix_kill($pid, 0), 'SIGTERM-ignoring child must be terminal after escalation.');
    }

    #[Test]
    public function catchableParentExceptionReapsExactChildOnLinux(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_kill')) {
            self::markTestSkipped('Linux catchable-cancellation custody proof is host-specific.');
        }

        $pidFile = tempnam(sys_get_temp_dir(), 'waaseyaa_project_init_cancel_');
        self::assertIsString($pidFile);
        $iterations = 0;
        $runner = new ProcOpenProjectInitProcessRunner(
            maxRuntimeSeconds: 5.0,
            pollHook: function () use (&$iterations, $pidFile): void {
                if (++$iterations > 5 && is_file($pidFile) && filesize($pidFile) > 0) {
                    throw new \RuntimeException('cancelled');
                }
            },
        );

        try {
            $runner->run(
                command: [PHP_BINARY, '-r', 'file_put_contents($argv[1], (string) getmypid()); while (true) { usleep(100_000); }', $pidFile],
                cwd: sys_get_temp_dir(),
                captureOutput: true,
            );
            self::fail('Expected catchable parent cancellation.');
        } catch (\RuntimeException $exception) {
            self::assertSame('cancelled', $exception->getMessage());
        }

        $pid = (int) file_get_contents($pidFile);
        @unlink($pidFile);
        self::assertGreaterThan(0, $pid);
        self::assertFalse(@posix_kill($pid, 0), 'Exact child must be terminal after catchable parent exception.');
    }

    #[Test]
    public function cleanupFailureReturns006WithoutLeavingLiveOrphanOnLinux(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_kill')) {
            self::markTestSkipped('Linux cleanup-failure control-flow proof is host-specific.');
        }

        $pidFile = tempnam(sys_get_temp_dir(), 'waaseyaa_project_init_cleanup_fail_');
        self::assertIsString($pidFile);
        $iterations = 0;
        $runner = new ProcOpenProjectInitProcessRunner(
            maxRuntimeSeconds: 5.0,
            pollHook: function () use (&$iterations, $pidFile): void {
                if (++$iterations > 5 && is_file($pidFile) && filesize($pidFile) > 0) {
                    throw new \RuntimeException('cancelled');
                }
            },
            terminalConfirmationProbe: static fn (): bool => false,
        );

        $result = $runner->run(
            command: [PHP_BINARY, '-r', 'file_put_contents($argv[1], (string) getmypid()); while (true) { usleep(100_000); }', $pidFile],
            cwd: sys_get_temp_dir(),
            captureOutput: true,
        );

        self::assertSame(ProjectInitProcessResult::ERROR_CHILD_CLEANUP_FAILED, $result->errorCode);
        self::assertGreaterThan(0, (int) $result->childPid);
        self::assertStringContainsString('cleanup_failed_after=', (string) $result->diagnostic);
        $pid = (int) file_get_contents($pidFile);
        @unlink($pidFile);
        self::assertFalse(@posix_kill($pid, 0), 'Cleanup-failure control flow must not leave a live orphan.');
    }

    #[Test]
    public function timeoutWithFailedCleanupReports006AndPreservesInitiating002(): void
    {
        $result = new ProcOpenProjectInitProcessRunner(
            maxRuntimeSeconds: 0.2,
            terminalConfirmationProbe: static fn (): bool => false,
        )->run(
            command: [PHP_BINARY, '-r', 'while (true) {}'],
            cwd: sys_get_temp_dir(),
            captureOutput: true,
        );

        self::assertSame(ProjectInitProcessResult::ERROR_CHILD_CLEANUP_FAILED, $result->errorCode);
        self::assertGreaterThan(0, $result->childPid);
        self::assertStringContainsString(ProjectInitProcessResult::ERROR_CHILD_TIMEOUT, $result->diagnostic);
    }

    #[Test]
    public function overflowWithFailedCleanupReports006AndPreservesInitiating003(): void
    {
        $result = new ProcOpenProjectInitProcessRunner(
            maxOutputBytesPerStream: 64,
            terminalConfirmationProbe: static fn (): bool => false,
        )->run(
            command: [PHP_BINARY, '-r', 'echo str_repeat("x", 8192);'],
            cwd: sys_get_temp_dir(),
            captureOutput: true,
        );

        self::assertSame(ProjectInitProcessResult::ERROR_CHILD_CLEANUP_FAILED, $result->errorCode);
        self::assertGreaterThan(0, $result->childPid);
        self::assertStringContainsString(ProjectInitProcessResult::ERROR_CHILD_OUTPUT_LIMIT, $result->diagnostic);
    }

    #[Test]
    public function attachedModeDoesNotCaptureOutput(): void
    {
        $result = new ProcOpenProjectInitProcessRunner()->run(
            command: [PHP_BINARY, '-r', 'fwrite(STDOUT, "visible");'],
            cwd: sys_get_temp_dir(),
            captureOutput: false,
        );

        self::assertSame(0, $result->exitCode);
        self::assertSame('', $result->stdout);
        self::assertSame('', $result->stderr);
    }

    #[Test]
    public function missingExecutableBinaryReturnsStartFailure(): void
    {
        $result = new ProcOpenProjectInitProcessRunner()->run(
            command: [sys_get_temp_dir() . '/waaseyaa-missing-php-' . bin2hex(random_bytes(6)), '-r', 'echo 1;'],
            cwd: sys_get_temp_dir(),
            captureOutput: true,
        );

        self::assertTrue($result->hasRunnerError());
        self::assertSame(ProjectInitProcessResult::ERROR_CHILD_START_FAILED, $result->errorCode);
    }

    #[Test]
    public function missingWorkingDirectoryReturnsStartFailure(): void
    {
        $result = new ProcOpenProjectInitProcessRunner()->run(
            command: [PHP_BINARY, '-r', 'echo 1;'],
            cwd: sys_get_temp_dir() . '/waaseyaa-missing-cwd-' . bin2hex(random_bytes(6)),
            captureOutput: true,
        );

        self::assertTrue($result->hasRunnerError());
        self::assertSame(ProjectInitProcessResult::ERROR_CHILD_START_FAILED, $result->errorCode);
    }

    private function runLongLivedChildAndReturnPid(ProcOpenProjectInitProcessRunner $runner): int
    {
        $pidFile = tempnam(sys_get_temp_dir(), 'waaseyaa_project_init_pid_');
        self::assertIsString($pidFile);
        $child = <<<'PHP'
file_put_contents($argv[1], (string) getmypid());
while (true) { usleep(100_000); }
PHP;

        $result = $runner->run(
            command: [PHP_BINARY, '-r', $child, $pidFile],
            cwd: sys_get_temp_dir(),
            captureOutput: true,
        );

        self::assertTrue($result->hasRunnerError());
        $pid = (int) file_get_contents($pidFile);
        @unlink($pidFile);

        return $pid;
    }

    private function createWorkingDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/waaseyaa_project_init_' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));

        return $directory;
    }
}
