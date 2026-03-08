<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Ingestion;

/**
 * Immutable value object representing a validated ingestion envelope.
 *
 * Construct only via EnvelopeValidator — the validator guarantees all
 * required fields are present and normalized before this object is created.
 */
final readonly class Envelope
{
    public function __construct(
        public string $source,
        public string $type,
        public array $payload,
        public string $timestamp,
        public string $traceId,
        public ?string $tenantId = null,
        public array $metadata = [],
    ) {}

    /** @return array{source: string, type: string, payload: array<string, mixed>, timestamp: string, trace_id: string, tenant_id: ?string, metadata: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'source'    => $this->source,
            'type'      => $this->type,
            'payload'   => $this->payload,
            'timestamp' => $this->timestamp,
            'trace_id'  => $this->traceId,
            'tenant_id' => $this->tenantId,
            'metadata'  => $this->metadata,
        ];
    }
}
