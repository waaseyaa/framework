<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Log\Processor;

use Waaseyaa\Foundation\Log\LogRecord;

/**
 * Adds HTTP request context (method, URI, request ID) to every log entry.
 *
 * Intended for use during HTTP request handling only. The kernel registers
 * this processor after routing so that all subsequent log entries carry
 * request context automatically.
 */
final class RequestContextProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly string $method,
        private readonly string $uri,
        private readonly ?string $requestId = null,
    ) {}

    public function process(LogRecord $record): LogRecord
    {
        $context = ['http_method' => $this->method, 'uri' => $this->uri];

        if ($this->requestId !== null) {
            $context['request_id'] = $this->requestId;
        }

        return new LogRecord(
            level: $record->level,
            message: $record->message,
            context: array_merge($record->context, $context),
            channel: $record->channel,
            timestamp: $record->timestamp,
        );
    }
}
