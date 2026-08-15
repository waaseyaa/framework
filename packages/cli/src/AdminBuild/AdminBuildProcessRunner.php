<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\AdminBuild;

use Waaseyaa\Foundation\Log\Processor\RedactorProcessor;

final class AdminBuildProcessRunner implements AdminBuildProcessRunnerInterface
{
    public function __construct(private readonly int $maxOutputBytesPerStream = 16_777_216)
    {
        if ($maxOutputBytesPerStream < 1) {
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
    ): int {
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
        $lastExitCode = -1;

        try {
            while (true) {
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

        if ($overflow) {
            throw new AdminBuildProcessException('child-output-limit');
        }

        $safeStdout = $sanitizer->sanitizeText($buffers[1]);
        $safeStderr = $sanitizer->sanitizeText($buffers[2]);
        $safeCombined = $sanitizer->sanitizeText($buffers[1] . $buffers[2]);
        if ($safeStdout === $buffers[1]
            && $safeStderr === $buffers[2]
            && $safeCombined !== $buffers[1] . $buffers[2]) {
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

        return $lastExitCode >= 0 ? $lastExitCode : $closedExitCode;
    }
}
