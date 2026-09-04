<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Support\Dispatch;

use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LogLevel;

/** A logger that is itself broken — every call throws, standing in for a failed sink. */
final class ThrowingLogger implements LoggerInterface
{
    public function emergency(string|\Stringable $message, array $context = []): void
    {
        throw new \RuntimeException('logger offline');
    }

    public function alert(string|\Stringable $message, array $context = []): void
    {
        throw new \RuntimeException('logger offline');
    }

    public function critical(string|\Stringable $message, array $context = []): void
    {
        throw new \RuntimeException('logger offline');
    }

    public function error(string|\Stringable $message, array $context = []): void
    {
        throw new \RuntimeException('logger offline');
    }

    public function warning(string|\Stringable $message, array $context = []): void
    {
        throw new \RuntimeException('logger offline');
    }

    public function notice(string|\Stringable $message, array $context = []): void
    {
        throw new \RuntimeException('logger offline');
    }

    public function info(string|\Stringable $message, array $context = []): void
    {
        throw new \RuntimeException('logger offline');
    }

    public function debug(string|\Stringable $message, array $context = []): void
    {
        throw new \RuntimeException('logger offline');
    }

    public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
    {
        throw new \RuntimeException('logger offline');
    }
}
