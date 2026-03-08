<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Ingestion;

/**
 * Canonical ingestion error value object.
 *
 * Every ingestion failure — envelope validation, payload validation, or
 * pipeline-level — is represented as an IngestionError. This gives
 * operators a single, predictable shape for diagnostics and logging.
 */
final readonly class IngestionError
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public IngestionErrorCode $code,
        public string $message,
        public string $field,
        public ?string $traceId = null,
        public array $details = [],
    ) {}

    /**
     * @return array{code: string, message: string, field: string, trace_id: ?string, details: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'code'     => $this->code->value,
            'message'  => $this->message,
            'field'    => $this->field,
            'trace_id' => $this->traceId,
            'details'  => $this->details,
        ];
    }
}
