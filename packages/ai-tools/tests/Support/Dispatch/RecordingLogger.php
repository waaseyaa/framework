<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Support\Dispatch;

use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LogLevel;

/** Captures log calls so a test can assert what an operator would actually see. */
final class RecordingLogger implements LoggerInterface
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->capture('emergency', $message, $context);
    }

    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->capture('alert', $message, $context);
    }

    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->capture('critical', $message, $context);
    }

    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->capture('error', $message, $context);
    }

    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->capture('warning', $message, $context);
    }

    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->capture('notice', $message, $context);
    }

    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->capture('info', $message, $context);
    }

    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->capture('debug', $message, $context);
    }

    public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
    {
        $this->capture($level->value, $message, $context);
    }

    /** @return list<array{level: string, message: string, context: array<string, mixed>}> */
    public function withLevel(string $level): array
    {
        return array_values(array_filter(
            $this->records,
            static fn(array $record): bool => $record['level'] === $level,
        ));
    }

    /** @param array<string, mixed> $context */
    private function capture(string $level, string|\Stringable $message, array $context): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
