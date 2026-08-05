<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp;

/** Safe wire refusal produced while validating protocol-era coherence. */
final class McpProtocolRequestException extends \RuntimeException
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly int $jsonRpcCode,
        string $message,
        public readonly int $httpStatus,
        public readonly array $data = [],
        public readonly string $reason = 'protocol_validation_failed',
    ) {
        parent::__construct($message);
    }
}
