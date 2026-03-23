<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Log;

final class NullLogger implements LoggerInterface
{
    use LoggerTrait;

    public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
    {
        // Intentionally discards all messages.
    }
}
