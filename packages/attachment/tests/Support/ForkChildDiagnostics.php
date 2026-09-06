<?php

declare(strict_types=1);

namespace Waaseyaa\Attachment\Tests\Support;

/**
 * Bounded per-child diagnostic capture for pcntl_fork() integration tests.
 *
 * Children write one JSON report per failure before exiting non-zero; the parent
 * reaps every child, verifies normal exit semantics, and surfaces stage/class/
 * message evidence that plain exit(1) would discard.
 */
final class ForkChildDiagnostics
{
    private const int MAX_MESSAGE_LENGTH = 500;

    public function __construct(
        private readonly string $directory,
    ) {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0o700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException(sprintf('Cannot create fork diagnostic directory "%s".', $this->directory));
        }
    }

    public static function createInDirectory(string $parentDirectory): self
    {
        return new self($parentDirectory . '/fork-diagnostics-' . bin2hex(random_bytes(4)));
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function childReportPath(int $childIndex): string
    {
        return $this->directory . '/child-' . $childIndex . '.json';
    }

    /**
     * Child-side: persist bounded failure evidence, then exit non-zero.
     */
    public function childExitWithFailure(int $childIndex, string $stage, \Throwable $exception): never
    {
        $this->writeReport($childIndex, [
            'child' => $childIndex,
            'stage' => $stage,
            'class' => $exception::class,
            'message' => self::truncateMessage($exception->getMessage()),
        ]);
        exit(1);
    }

    /**
     * @param array<int, int> $pidToChildIndex
     *
     * @return list<string>
     */
    public function waitReapAndCollectFailures(array $pidToChildIndex): array
    {
        $failures = [];

        foreach ($pidToChildIndex as $pid => $childIndex) {
            $status = 0;
            $waitedPid = $this->waitForPid($pid, $status);

            if ($waitedPid === -1) {
                $errno = function_exists('pcntl_get_last_error') ? pcntl_get_last_error() : 0;
                $failures[] = sprintf(
                    'child %d: pcntl_waitpid(%d) failed (errno=%d)',
                    $childIndex,
                    $pid,
                    $errno,
                );
                continue;
            }

            if ($waitedPid !== $pid) {
                $failures[] = sprintf(
                    'child %d: waitpid returned unexpected pid %d (expected %d)',
                    $childIndex,
                    $waitedPid,
                    $pid,
                );
                continue;
            }

            if (!pcntl_wifexited($status)) {
                if (pcntl_wifsignaled($status)) {
                    $failures[] = sprintf(
                        'child %d: terminated by signal %d',
                        $childIndex,
                        pcntl_wtermsig($status),
                    );
                } else {
                    $failures[] = sprintf('child %d: did not exit normally (status=%d)', $childIndex, $status);
                }
                continue;
            }

            $exitCode = pcntl_wexitstatus($status);
            if ($exitCode !== 0) {
                $report = $this->readReport($childIndex);
                if ($report !== null) {
                    $failures[] = sprintf(
                        'child %d: exit=%d stage=%s class=%s message=%s',
                        $childIndex,
                        $exitCode,
                        (string) ($report['stage'] ?? '?'),
                        (string) ($report['class'] ?? '?'),
                        (string) ($report['message'] ?? '?'),
                    );
                } else {
                    $failures[] = sprintf(
                        'child %d: exit=%d (no diagnostic report written)',
                        $childIndex,
                        $exitCode,
                    );
                }
            }
        }

        return $failures;
    }

    /**
     * @param list<string> $failures
     */
    public function formatBoundedSummary(array $failures, int $maxLines = 30): string
    {
        if ($failures === []) {
            return '';
        }

        $bounded = array_slice($failures, 0, $maxLines);
        $summary = implode("\n", $bounded);
        if (\count($failures) > $maxLines) {
            $summary .= sprintf("\n... and %d more child failure(s)", \count($failures) - $maxLines);
        }

        return $summary;
    }

    public function cleanup(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        foreach (glob($this->directory . '/*') ?: [] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        @rmdir($this->directory);
    }

    /**
     * @internal Focused regression hook for UTF-8 message bounding.
     */
    public static function boundMessageForTesting(string $message): string
    {
        return self::truncateMessage($message);
    }

    /**
     * @internal Focused regression hook for report JSON encoding.
     *
     * @param array{child:int,stage:string,class:string,message:string} $payload
     */
    public static function encodeReportPayloadForTesting(array $payload): string
    {
        return self::encodeReportPayload($payload);
    }

    /**
     * @internal Focused regression hook for EINTR-safe waitpid behavior.
     *
     * @param callable(int, int&): int $waitPid
     * @param callable(): int          $lastError
     */
    public static function waitForPidUsing(
        int $pid,
        int &$status,
        callable $waitPid,
        callable $lastError,
    ): int {
        do {
            $waitedPid = $waitPid($pid, $status);
            if ($waitedPid !== -1) {
                return $waitedPid;
            }

            if ($lastError() === \PCNTL_EINTR) {
                continue;
            }

            return -1;
        } while (true);
    }

    /**
     * @param array{child:int,stage:string,class:string,message:string} $payload
     */
    private function writeReport(int $childIndex, array $payload): void
    {
        $path = $this->childReportPath($childIndex);
        $encoded = self::encodeReportPayload($payload);
        $written = file_put_contents($path, $encoded, LOCK_EX);
        if ($written === false) {
            fwrite(STDERR, sprintf("fork-diagnostic-write-failed: child=%d path=%s\n", $childIndex, $path));
        }
    }

    /**
     * @param array{child:int,stage:string,class:string,message:string} $payload
     */
    private static function encodeReportPayload(array $payload): string
    {
        return json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }

    /**
     * @return array{child:int,stage:string,class:string,message:string}|null
     */
    private function readReport(int $childIndex): ?array
    {
        $path = $this->childReportPath($childIndex);
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($decoded) ? $decoded : null;
    }

    private function waitForPid(int $pid, int &$status): int
    {
        return self::waitForPidUsing(
            $pid,
            $status,
            static fn (int $childPid, int &$childStatus): int => pcntl_waitpid($childPid, $childStatus),
            static fn (): int => function_exists('pcntl_get_last_error') ? pcntl_get_last_error() : 0,
        );
    }

    private static function truncateMessage(string $message): string
    {
        if (\strlen($message) <= self::MAX_MESSAGE_LENGTH) {
            return $message;
        }

        if (function_exists('mb_strcut')) {
            return mb_strcut($message, 0, self::MAX_MESSAGE_LENGTH, 'UTF-8') . '…';
        }

        $truncated = substr($message, 0, self::MAX_MESSAGE_LENGTH);
        $truncated = preg_replace('/[\x80-\xBF]+$/', '', $truncated) ?? $truncated;
        $truncated = preg_replace('/[\xC0-\xDF]$/', '', $truncated) ?? $truncated;
        $truncated = preg_replace('/[\xE0-\xEF][\x80-\xBF]?$/', '', $truncated) ?? $truncated;
        $truncated = preg_replace('/[\xF0-\xF7][\x80-\xBF]{0,2}$/', '', $truncated) ?? $truncated;

        return $truncated . '…';
    }
}
