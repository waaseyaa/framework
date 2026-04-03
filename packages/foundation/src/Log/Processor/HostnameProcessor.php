<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Log\Processor;

use Waaseyaa\Foundation\Log\LogRecord;

final class HostnameProcessor implements ProcessorInterface
{
    private readonly string $hostname;

    public function __construct(?string $hostname = null)
    {
        $this->hostname = $hostname ?? (gethostname() ?: 'unknown');
    }

    public function process(LogRecord $record): LogRecord
    {
        return new LogRecord(
            level: $record->level,
            message: $record->message,
            context: array_merge($record->context, ['hostname' => $this->hostname]),
            channel: $record->channel,
            timestamp: $record->timestamp,
        );
    }
}
