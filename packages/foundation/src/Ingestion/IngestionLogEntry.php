<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Ingestion;

/**
 * A single ingestion log record.
 *
 * Both success and failure events share this structure. The status field
 * distinguishes outcomes; errors are present only on failure.
 */
final readonly class IngestionLogEntry
{
    public string $loggedAt;

    /**
     * @param list<IngestionError> $errors
     */
    public function __construct(
        public string $source,
        public string $type,
        public string $status,
        public string $traceId,
        public string $timestamp,
        public ?string $tenantId = null,
        public array $errors = [],
        string $loggedAt = '',
    ) {
        $this->loggedAt = $loggedAt !== ''
            ? $loggedAt
            : (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
    }

    public static function success(Envelope $envelope): self
    {
        return new self(
            source: $envelope->source,
            type: $envelope->type,
            status: 'accepted',
            traceId: $envelope->traceId,
            timestamp: $envelope->timestamp,
            tenantId: $envelope->tenantId,
        );
    }

    /**
     * @param list<IngestionError> $errors
     */
    public static function envelopeFailure(
        string $traceId,
        string $source,
        string $type,
        array $errors,
    ): self {
        return new self(
            source: $source,
            type: $type,
            status: 'rejected',
            traceId: $traceId,
            timestamp: (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            errors: $errors,
        );
    }

    /**
     * @param list<IngestionError> $errors
     */
    public static function payloadFailure(Envelope $envelope, array $errors): self
    {
        return new self(
            source: $envelope->source,
            type: $envelope->type,
            status: 'rejected',
            traceId: $envelope->traceId,
            timestamp: $envelope->timestamp,
            tenantId: $envelope->tenantId,
            errors: $errors,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $entry = [
            'source'    => $this->source,
            'type'      => $this->type,
            'status'    => $this->status,
            'trace_id'  => $this->traceId,
            'timestamp' => $this->timestamp,
            'logged_at' => $this->loggedAt,
        ];

        if ($this->tenantId !== null) {
            $entry['tenant_id'] = $this->tenantId;
        }

        if ($this->errors !== []) {
            $entry['errors'] = array_map(
                static fn(IngestionError $e) => $e->toArray(),
                $this->errors,
            );
        }

        return $entry;
    }
}
