<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Log;

use Waaseyaa\Foundation\Log\Handler\HandlerInterface;

final class ChannelLogger implements LoggerInterface
{
    use LoggerTrait;

    public function __construct(
        private readonly string $channel,
        private readonly HandlerInterface $handler,
    ) {}

    public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
    {
        $record = new LogRecord(
            level: $level,
            message: (string) $message,
            context: $context,
            channel: $this->channel,
        );

        $this->handler->handle($record);
    }
}
