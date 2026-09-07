<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\ProjectInit;

final class ProcOpenProjectInitProcessRunner implements ProjectInitProcessRunnerInterface
{
    private const CAPTURE_OK = 0;
    private const CAPTURE_OVERFLOW = 1;
    private const CAPTURE_FAILED = 2;
    private const CAPTURE_TIMED_OUT = 3;
    private const CAPTURE_READ_BYTES = 65_536;
    private const POLL_MICROSECONDS = 10_000;

    private readonly bool $capturedFileTransport;

    /** @var \Closure(string): bool */
    private readonly \Closure $captureUnlinker;

    /**
     * @param null|\Closure(): void $pollHook test-only seam invoked once per poll iteration
     * @param null|\Closure(resource): bool $terminalConfirmationProbe test-only seam; false means not terminal
     * @param null|bool $capturedFileTransport test-only transport override; null selects files on Windows
     * @param null|\Closure(string): bool $captureUnlinker test-only filesystem seam
     */
    public function __construct(
        private readonly int $maxOutputBytesPerStream = 16_777_216,
        private readonly float $maxRuntimeSeconds = 900.0,
        private readonly float $terminateGraceSeconds = 0.25,
        private readonly ?\Closure $pollHook = null,
        private readonly ?\Closure $terminalConfirmationProbe = null,
        ?bool $capturedFileTransport = null,
        ?\Closure $captureUnlinker = null,
    ) {
        if ($maxOutputBytesPerStream < 1 || $maxRuntimeSeconds <= 0 || $terminateGraceSeconds <= 0) {
            throw new \InvalidArgumentException('Project init runner bounds must be positive.');
        }

        $this->capturedFileTransport = $capturedFileTransport ?? PHP_OS_FAMILY === 'Windows';
        $this->captureUnlinker = $captureUnlinker ?? static fn(string $path): bool => @unlink($path);
    }

    /**
     * @param non-empty-list<string> $command
     */
    public function run(array $command, string $cwd, bool $captureOutput): ProjectInitProcessResult
    {
        $fileCapture = null;
        if ($captureOutput && $this->capturedFileTransport) {
            $fileCapture = $this->prepareFileCapture($cwd);
            if ($fileCapture === null) {
                return ProjectInitProcessResult::startFailed();
            }
        }

        $descriptorSpec = $this->descriptorSpec($captureOutput, $fileCapture);
        $pipes = [];
        try {
            $process = @proc_open(
                $command,
                $descriptorSpec,
                $pipes,
                $cwd,
                null,
                ['bypass_shell' => true, 'suppress_errors' => true],
            );
        } catch (\Throwable) {
            $this->closeFileCapture($fileCapture);

            return ProjectInitProcessResult::startFailed();
        }
        if (!is_resource($process)) {
            $this->closeFileCapture($fileCapture);

            return ProjectInitProcessResult::startFailed();
        }

        $stdout = '';
        $stderr = '';
        $overflow = false;
        $timedOut = false;
        $captureFailed = false;
        $lastExitCode = -1;
        $catchableException = null;

        if ($fileCapture !== null && !$this->unlinkFileCaptureNames($fileCapture)) {
            $captureFailed = true;
            $this->escalateTermination($process);
        }

        try {
            if ($captureOutput && $fileCapture === null) {
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
            }

            $started = (int) hrtime(true);
            while (!$captureFailed) {
                if ($this->pollHook !== null) {
                    ($this->pollHook)();
                }

                if ($this->deadlineExceeded($started)) {
                    $timedOut = true;
                    $this->escalateTermination($process);
                    break;
                }

                if ($fileCapture !== null) {
                    $captureState = $this->drainFileCapture($fileCapture, $stdout, $stderr, false, $started);
                    if ($captureState !== self::CAPTURE_OK) {
                        $overflow = $captureState === self::CAPTURE_OVERFLOW;
                        $timedOut = $captureState === self::CAPTURE_TIMED_OUT;
                        $captureFailed = $captureState === self::CAPTURE_FAILED;
                        $this->escalateTermination($process);
                        break;
                    }
                } elseif ($captureOutput) {
                    $overflow = $this->drainPipes($pipes, $stdout, $stderr, $overflow, $process);
                    if ($overflow) {
                        $this->escalateTermination($process);
                        break;
                    }
                } elseif (!proc_get_status($process)['running']) {
                    break;
                } else {
                    usleep(self::POLL_MICROSECONDS);
                }

                $status = proc_get_status($process);
                if (!$status['running']) {
                    $lastExitCode = $status['exitcode'];
                    if ($fileCapture !== null) {
                        $captureState = $this->drainFileCapture($fileCapture, $stdout, $stderr, true, $started);
                        $overflow = $captureState === self::CAPTURE_OVERFLOW;
                        $timedOut = $captureState === self::CAPTURE_TIMED_OUT;
                        $captureFailed = $captureState === self::CAPTURE_FAILED;
                    } elseif ($captureOutput) {
                        $overflow = $this->drainRemaining($pipes, $stdout, $stderr, $overflow, $process);
                    }
                    if ($overflow || $timedOut || $captureFailed) {
                        $this->escalateTermination($process);
                    }
                    break;
                }

                if ($fileCapture !== null) {
                    usleep(self::POLL_MICROSECONDS);
                }
            }
        } catch (\Throwable $exception) {
            $catchableException = $exception;
            $this->escalateTermination($process);
        }

        if ($captureOutput && $fileCapture === null) {
            if (isset($pipes[1]) && is_resource($pipes[1])) {
                fclose($pipes[1]);
            }
            if (isset($pipes[2]) && is_resource($pipes[2])) {
                fclose($pipes[2]);
            }
        }

        if (!$this->closeFileCapture($fileCapture)) {
            $captureFailed = true;
            $this->escalateTermination($process);
        }

        $childPid = $this->childPid($process);
        $cleanup = $this->finalizeChildProcess($process);
        $process = null;

        if (!$this->removeFileCaptureNames($fileCapture)) {
            $captureFailed = true;
        }

        if (!$cleanup->confirmed) {
            if ($catchableException !== null && !$captureFailed) {
                return ProjectInitProcessResult::cleanupFailed(
                    $childPid,
                    $this->boundedDiagnostic('cleanup_failed_after=' . $catchableException::class),
                );
            }

            $cause = $captureFailed
                ? ProjectInitProcessResult::ERROR_CHILD_CAPTURE_FAILED
                : ($timedOut
                    ? ProjectInitProcessResult::ERROR_CHILD_TIMEOUT
                    : ($overflow ? ProjectInitProcessResult::ERROR_CHILD_OUTPUT_LIMIT : 'child_exit'));

            return ProjectInitProcessResult::cleanupFailed(
                $childPid,
                $this->boundedDiagnostic('child_not_terminal_after_escalation; initiating=' . $cause),
            );
        }
        if ($captureFailed) {
            return ProjectInitProcessResult::captureFailed();
        }
        if ($catchableException !== null) {
            throw $catchableException;
        }
        if ($timedOut) {
            return ProjectInitProcessResult::timedOut();
        }
        if ($overflow) {
            return ProjectInitProcessResult::outputLimitExceeded();
        }

        $exitCode = $lastExitCode >= 0 ? $lastExitCode : $cleanup->exitCode;

        return new ProjectInitProcessResult(
            exitCode: $exitCode,
            stdout: $stdout,
            stderr: $stderr,
        );
    }

    /** @return array<int, mixed> */
    private function descriptorSpec(bool $captureOutput, ?ProjectInitFileCapture $fileCapture): array
    {
        if (!$captureOutput) {
            return [0 => STDIN, 1 => STDOUT, 2 => STDERR];
        }
        if ($fileCapture !== null) {
            return [
                0 => ['file', $this->nullInputDevice(), 'r'],
                1 => ['file', $fileCapture->stdoutPath, 'wb'],
                2 => ['file', $fileCapture->stderrPath, 'wb'],
            ];
        }

        return [0 => ['file', $this->nullInputDevice(), 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    }

    private function prepareFileCapture(string $cwd): ?ProjectInitFileCapture
    {
        $tempDirectory = realpath(sys_get_temp_dir());
        $workingDirectory = realpath($cwd);
        if (!is_string($tempDirectory) || !is_string($workingDirectory) || !$this->isAbsolutePath($tempDirectory)) {
            return null;
        }
        if ($this->pathIsWithin($tempDirectory, $workingDirectory)) {
            return null;
        }

        $created = [];
        $readers = [];

        try {
            $stdoutPath = $tempDirectory . DIRECTORY_SEPARATOR . 'waaseyaa-project-init-' . bin2hex(random_bytes(16)) . '.stdout';
            $stderrPath = $tempDirectory . DIRECTORY_SEPARATOR . 'waaseyaa-project-init-' . bin2hex(random_bytes(16)) . '.stderr';
            foreach ([$stdoutPath, $stderrPath] as $path) {
                $seed = @fopen($path, 'x+b');
                if (!is_resource($seed)) {
                    throw new \RuntimeException('capture seed creation failed');
                }
                $created[] = $path;
                if (!fclose($seed)) {
                    throw new \RuntimeException('capture seed close failed');
                }
                $reader = @fopen($path, 'rb');
                if (!is_resource($reader)) {
                    throw new \RuntimeException('capture reader open failed');
                }
                $readers[] = $reader;
            }
        } catch (\Throwable) {
            foreach ($readers as $reader) {
                @fclose($reader);
            }
            foreach ($created as $path) {
                @unlink($path);
            }

            return null;
        }

        return new ProjectInitFileCapture($stdoutPath, $stderrPath, $readers[0], $readers[1]);
    }

    private function unlinkFileCaptureNames(ProjectInitFileCapture $capture): bool
    {
        $success = true;
        foreach ([$capture->stdoutPath, $capture->stderrPath] as $path) {
            try {
                if (!(($this->captureUnlinker)($path))) {
                    $success = false;
                }
            } catch (\Throwable) {
                $success = false;
            }
        }

        return $success;
    }

    private function closeFileCapture(?ProjectInitFileCapture $capture): bool
    {
        if ($capture === null) {
            return true;
        }

        $success = true;
        foreach ([$capture->stdoutReader, $capture->stderrReader] as $reader) {
            if (is_resource($reader) && !@fclose($reader)) {
                $success = false;
            }
        }
        return $this->removeFileCaptureNames($capture) && $success;
    }

    private function removeFileCaptureNames(?ProjectInitFileCapture $capture): bool
    {
        if ($capture === null) {
            return true;
        }

        $success = true;
        foreach ([$capture->stdoutPath, $capture->stderrPath] as $path) {
            if (file_exists($path) && !@unlink($path)) {
                $success = false;
            }
        }

        return $success;
    }

    private function drainFileCapture(
        ProjectInitFileCapture $capture,
        string &$stdout,
        string &$stderr,
        bool $final,
        int $started,
    ): int {
        $state = $this->drainFileStream($capture->stdoutReader, $capture->stdoutOffset, $stdout, $final, $started);
        if ($state !== self::CAPTURE_OK) {
            return $state;
        }

        return $this->drainFileStream($capture->stderrReader, $capture->stderrOffset, $stderr, $final, $started);
    }

    /** @param resource $reader */
    private function drainFileStream($reader, int &$offset, string &$buffer, bool $final, int $started): int
    {
        /** @var array<string|int, mixed>|false $stat */
        $stat = @fstat($reader);
        $size = $stat === false ? null : ($stat['size'] ?? null);
        if (!is_int($size)) {
            return self::CAPTURE_FAILED;
        }
        if ($size > $this->maxOutputBytesPerStream) {
            return self::CAPTURE_OVERFLOW;
        }

        while ($offset < $size) {
            if ($this->deadlineExceeded($started)) {
                return self::CAPTURE_TIMED_OUT;
            }
            if (@fseek($reader, $offset) !== 0) {
                return self::CAPTURE_FAILED;
            }
            $chunk = @fread($reader, min(self::CAPTURE_READ_BYTES, $size - $offset));
            if (!is_string($chunk)) {
                return self::CAPTURE_FAILED;
            }
            if ($chunk === '') {
                return $final ? self::CAPTURE_FAILED : self::CAPTURE_OK;
            }
            $buffer .= $chunk;
            $offset += strlen($chunk);
        }

        return self::CAPTURE_OK;
    }

    private function deadlineExceeded(int $started): bool
    {
        return (hrtime(true) - $started) / 1_000_000_000 >= $this->maxRuntimeSeconds;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
            || str_starts_with($path, '\\\\');
    }

    private function pathIsWithin(string $path, string $directory): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $directory = rtrim(str_replace('\\', '/', $directory), '/');
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $directory = strtolower($directory);
        }

        return $path === $directory || str_starts_with($path . '/', $directory . '/');
    }

    /** @param array<int, resource> $pipes @param resource $process */
    private function drainPipes(array $pipes, string &$stdout, string &$stderr, bool $overflow, $process): bool
    {
        $read = [];
        foreach ([1, 2] as $index) {
            if (isset($pipes[$index]) && is_resource($pipes[$index]) && !feof($pipes[$index])) {
                $read[] = $pipes[$index];
            }
        }
        if ($read === []) {
            usleep(self::POLL_MICROSECONDS);

            return false;
        }

        $write = null;
        $except = null;
        @stream_select($read, $write, $except, 0, 200_000);
        foreach ($read as $stream) {
            $index = $stream === $pipes[1] ? 1 : 2;
            $chunk = fread($stream, self::CAPTURE_READ_BYTES);
            if (!is_string($chunk) || $chunk === '') {
                continue;
            }
            if ($this->wouldOverflow($index === 1 ? $stdout : $stderr, $chunk)) {
                return true;
            }
            if ($index === 1) {
                $stdout .= $chunk;
            } else {
                $stderr .= $chunk;
            }
        }

        return false;
    }

    /** @param array<int, resource> $pipes @param resource $process */
    private function drainRemaining(array $pipes, string &$stdout, string &$stderr, bool $overflow, $process): bool
    {
        foreach ([1, 2] as $index) {
            if (!isset($pipes[$index]) || !is_resource($pipes[$index])) {
                continue;
            }
            $remaining = stream_get_contents($pipes[$index]);
            if (!is_string($remaining) || $remaining === '') {
                continue;
            }
            if ($this->wouldOverflow($index === 1 ? $stdout : $stderr, $remaining)) {
                return true;
            }
            if ($index === 1) {
                $stdout .= $remaining;
            } else {
                $stderr .= $remaining;
            }
        }

        return false;
    }

    private function wouldOverflow(string $buffer, string $chunk): bool
    {
        return strlen($buffer) + strlen($chunk) > $this->maxOutputBytesPerStream;
    }

    /** @param resource $process */
    private function escalateTermination($process): void
    {
        @proc_terminate($process);
        if ($this->waitUntilNotRunning($process, $this->terminateGraceSeconds)) {
            return;
        }

        $this->hardKill($process);
        $this->waitUntilNotRunning($process, $this->terminateGraceSeconds);
    }

    /** @param resource $process */
    private function hardKill($process): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            @proc_terminate($process);

            return;
        }

        @proc_terminate($process, 9);
    }

    /** @param resource $process */
    private function waitUntilNotRunning($process, float $maxWaitSeconds): bool
    {
        $deadline = hrtime(true) + (int) ($maxWaitSeconds * 1_000_000_000);
        do {
            if (!$this->isProcessRunning($process)) {
                return true;
            }
            usleep(self::POLL_MICROSECONDS);
        } while (hrtime(true) < $deadline);

        return !$this->isProcessRunning($process);
    }

    /**
     * @param resource $process
     *
     * @phpstan-impure
     */
    private function isProcessRunning($process): bool
    {
        return proc_get_status($process)['running'];
    }

    /** @param resource $process */
    private function isTerminalConfirmed($process): bool
    {
        if ($this->terminalConfirmationProbe !== null) {
            return ($this->terminalConfirmationProbe)($process);
        }

        return !$this->isProcessRunning($process);
    }

    /** @param resource $process */
    private function childPid($process): int
    {
        $status = proc_get_status($process);

        return $status['pid'];
    }

    /** @param resource $process */
    private function finalizeChildProcess($process): ProjectInitCleanupConfirmation
    {
        if ($this->isTerminalConfirmed($process)) {
            return new ProjectInitCleanupConfirmation(true, proc_close($process));
        }

        $this->escalateTermination($process);

        if ($this->isTerminalConfirmed($process)) {
            return new ProjectInitCleanupConfirmation(true, proc_close($process));
        }

        return new ProjectInitCleanupConfirmation(false, -1);
    }

    private function boundedDiagnostic(string $diagnostic): string
    {
        return substr($diagnostic, 0, 256);
    }

    private function nullInputDevice(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    }
}

/** @internal */
final class ProjectInitFileCapture
{
    /** @param resource $stdoutReader @param resource $stderrReader */
    public function __construct(
        public readonly string $stdoutPath,
        public readonly string $stderrPath,
        public $stdoutReader,
        public $stderrReader,
        public int $stdoutOffset = 0,
        public int $stderrOffset = 0,
    ) {}
}

/** @internal */
final readonly class ProjectInitCleanupConfirmation
{
    public function __construct(
        public bool $confirmed,
        public int $exitCode,
    ) {}
}
