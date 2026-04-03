<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Log\Processor;

use Waaseyaa\Foundation\Log\LogRecord;

final class RequestIdProcessor implements ProcessorInterface
{
    private readonly string $requestId;

    public function __construct(?string $requestId = null)
    {
        $this->requestId = $requestId ?? bin2hex(random_bytes(16));
    }

    public function process(LogRecord $record): LogRecord
    {
        return new LogRecord(
            level: $record->level,
            message: $record->message,
            context: array_merge($record->context, ['request_id' => $this->requestId]),
            channel: $record->channel,
            timestamp: $record->timestamp,
        );
    }
}
