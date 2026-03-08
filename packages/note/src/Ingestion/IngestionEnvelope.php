<?php

declare(strict_types=1);

namespace Waaseyaa\Note\Ingestion;

/**
 * A validated ingestion envelope for core.note.
 *
 * Construct only via IngestionEnvelope::fromValidated() after running
 * IngestionEnvelopeValidator — the validator guarantees all fields are present
 * and non-empty before this object is created.
 */
final class IngestionEnvelope
{
    private function __construct(
        public readonly string $envelopeVersion,
        public readonly string $source,
        public readonly string $ingestedAt,
        public readonly string $title,
        public readonly string $tenantId,
        public readonly string $body,
    ) {}

    /** @param array<string, mixed> $raw A raw array that has already passed IngestionEnvelopeValidator. */
    public static function fromValidated(array $raw): self
    {
        /** @var array<string, mixed> $payload */
        $payload = $raw['payload'];

        return new self(
            envelopeVersion: (string) $raw['envelope_version'],
            source:          trim((string) $raw['source']),
            ingestedAt:      trim((string) $raw['ingested_at']),
            title:           trim((string) $payload['title']),
            tenantId:        trim((string) $payload['tenant_id']),
            body:            isset($payload['body']) ? trim((string) $payload['body']) : '',
        );
    }
}
