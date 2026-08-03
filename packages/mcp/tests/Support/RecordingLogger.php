<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Support;

use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LogLevel;

/**
 * An in-memory {@see LoggerInterface} that keeps every call, so a test can assert
 * that exception detail reached the log rather than the caller.
 *
 * Lives under `tests/` (autoload-dev only) — it must never be reachable from a
 * production autoload map.
 */
final class RecordingLogger implements LoggerInterface
{
    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> level, message, context */
    public array $records = [];

    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [$level->value, (string) $message, $context];
    }

    /** Every context value ever logged, flattened — for "the secret is not here" assertions. */
    public function allContextAsString(): string
    {
        return json_encode(array_column($this->records, 2), JSON_THROW_ON_ERROR);
    }
}
