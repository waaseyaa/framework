<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\AdminBuild;

use Waaseyaa\Foundation\Log\Processor\RedactorProcessor;

final class AdminBuildProcessRunner implements AdminBuildProcessRunnerInterface
{
    public function __construct(
        private readonly int $maxOutputBytesPerStream = 16_777_216,
        private readonly float $maxRuntimeSeconds = 900.0,
    ) {
        if ($maxOutputBytesPerStream < 1 || $maxRuntimeSeconds <= 0) {
            throw new \InvalidArgumentException('Admin build output limit must be positive.');
        }
    }

    /**
     * @param non-empty-list<string> $command
     * @param array<string, string> $environment
     * @param callable(string): void $stdout
     * @param callable(string): void $stderr
     */
    public function run(
        array $command,
        string $cwd,
        array $environment,
        RedactorProcessor $sanitizer,
        callable $stdout,
        callable $stderr,
    ): AdminBuildProcessResult {
        $process = @proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
            $environment,
            ['bypass_shell' => true, 'suppress_errors' => true],
        );
        if (!is_resource($process)) {
            throw new AdminBuildProcessException('child-start-failed');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $buffers = [1 => '', 2 => ''];
        $overflow = false;
        $timedOut = false;
        $lastExitCode = -1;
        $started = hrtime(true);

        try {
            while (true) {
                if ((hrtime(true) - $started) / 1_000_000_000 >= $this->maxRuntimeSeconds) {
                    $timedOut = true;
                    $this->terminate($process);
                    break;
                }
                $read = [];
                foreach ([1, 2] as $index) {
                    if (!feof($pipes[$index])) {
                        $read[] = $pipes[$index];
                    }
                }
                if ($read !== []) {
                    $write = null;
                    $except = null;
                    @stream_select($read, $write, $except, 0, 200_000);
                    foreach ($read as $stream) {
                        $index = $stream === $pipes[1] ? 1 : 2;
                        $chunk = fread($stream, 65536);
                        if (!is_string($chunk) || $chunk === '') {
                            continue;
                        }
                        if (strlen($buffers[$index]) + strlen($chunk) > $this->maxOutputBytesPerStream) {
                            $overflow = true;
                        } elseif (!$overflow) {
                            $buffers[$index] .= $chunk;
                        }
                    }
                }

                if ($read === []) {
                    usleep(10_000);
                }

                $status = proc_get_status($process);
                if (!$status['running']) {
                    $lastExitCode = $status['exitcode'];
                    foreach ([1, 2] as $index) {
                        $remaining = stream_get_contents($pipes[$index]);
                        if (is_string($remaining) && $remaining !== '') {
                            if (strlen($buffers[$index]) + strlen($remaining) > $this->maxOutputBytesPerStream) {
                                $overflow = true;
                            } elseif (!$overflow) {
                                $buffers[$index] .= $remaining;
                            }
                        }
                    }
                    break;
                }
            }
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            $closedExitCode = proc_close($process);
        }

        if ($timedOut) {
            $buffers = [1 => '', 2 => ''];
            throw new AdminBuildProcessException('child-runtime-limit');
        }
        if ($overflow) {
            throw new AdminBuildProcessException('child-output-limit');
        }

        $npmErrorCode = $this->npmErrorCode($buffers[1] . "\n" . $buffers[2]);
        $safeStdout = $sanitizer->sanitizeText($buffers[1]);
        $safeStderr = $sanitizer->sanitizeText($buffers[2]);
        $stdoutContainsRegistered = $sanitizer->containsRegisteredRepresentation($buffers[1]);
        $stderrContainsRegistered = $sanitizer->containsRegisteredRepresentation($buffers[2]);
        $crossStreamRegistered = !$stdoutContainsRegistered
            && !$stderrContainsRegistered
            && ($sanitizer->containsRegisteredRepresentation($buffers[1] . $buffers[2])
                || $sanitizer->containsRegisteredRepresentation($buffers[2] . $buffers[1]));
        if ($crossStreamRegistered) {
            $safeStdout = '';
            $safeStderr = RedactorProcessor::SENTINEL;
        }
        $buffers = [1 => '', 2 => ''];
        if ($safeStdout !== '') {
            $stdout($safeStdout);
        }
        if ($safeStderr !== '') {
            $stderr($safeStderr);
        }

        return new AdminBuildProcessResult(
            exitCode: $lastExitCode >= 0 ? $lastExitCode : $closedExitCode,
            npmErrorCode: $npmErrorCode,
        );
    }

    /** @param resource $process */
    private function terminate($process): void
    {
        @proc_terminate($process);
        $deadline = hrtime(true) + 250_000_000;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                return;
            }
            usleep(10_000);
        } while (hrtime(true) < $deadline);

        @proc_terminate($process, 9);
    }

    private function npmErrorCode(string $output): ?string
    {
        if (preg_match('/(?:^|\R)npm (?:error|ERR!) code ([A-Z0-9_-]+)(?:\R|$)/', $output, $match) !== 1) {
            return null;
        }

        return $match[1];
    }
}
