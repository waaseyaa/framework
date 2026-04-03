<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Log;

use Waaseyaa\Foundation\Log\Handler\HandlerInterface;

/**
 * Adapts a LoggerInterface to HandlerInterface for backward compatibility.
 *
 * @internal Used by LogManager to wrap Phase A LoggerInterface instances.
 */
final class LegacyLoggerHandler implements HandlerInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(LogRecord $record): void
    {
        $this->logger->log($record->level, $record->message, $record->context);
    }
}
