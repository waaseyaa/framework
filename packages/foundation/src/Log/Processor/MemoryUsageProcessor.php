<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Log\Processor;

use Waaseyaa\Foundation\Log\LogRecord;

final class MemoryUsageProcessor implements ProcessorInterface
{
    public function process(LogRecord $record): LogRecord
    {
        return new LogRecord(
            level: $record->level,
            message: $record->message,
            context: array_merge($record->context, [
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1_048_576, 2),
            ]),
            channel: $record->channel,
            timestamp: $record->timestamp,
        );
    }
}
