<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Ingestion;

use Symfony\Component\Uid\Uuid;

/**
 * Validates and normalizes raw ingestion envelopes against the canonical schema.
 *
 * Responsibilities:
 *   - Validate required fields (source, type, payload, timestamp)
 *   - Reject unknown top-level fields (strict schema)
 *   - Auto-generate trace_id if missing
 *   - Validate field types and formats
 *   - Return a normalized Envelope DTO or throw InvalidEnvelopeException
 */
final class EnvelopeValidator
{
    private const REQUIRED_FIELDS = ['source', 'type', 'payload', 'timestamp'];

    private const ALLOWED_FIELDS = [
        'source', 'type', 'payload', 'timestamp',
        'trace_id', 'tenant_id', 'metadata',
    ];

    /**
     * Validate a raw envelope array and return a normalized Envelope DTO.
     *
     * @param array<string, mixed> $raw The raw envelope data.
     * @throws InvalidEnvelopeException If validation fails.
     */
    public function validate(array $raw): Envelope
    {
        /** @var list<IngestionError> $errors */
        $errors = [];

        // Extract trace_id early so we can include it in errors and the exception.
        $traceId = isset($raw['trace_id']) && is_string($raw['trace_id']) ? $raw['trace_id'] : null;

        // Check for unknown fields.
        $unknownFields = array_diff(array_keys($raw), self::ALLOWED_FIELDS);
        foreach ($unknownFields as $field) {
            $errors[] = new IngestionError(
                code:    IngestionErrorCode::ENVELOPE_FIELD_UNKNOWN,
                message: "Unknown field '{$field}'. Allowed fields: " . implode(', ', self::ALLOWED_FIELDS) . '.',
                field:   (string) $field,
                traceId: $traceId,
            );
        }

        // Validate required fields.
        foreach (self::REQUIRED_FIELDS as $field) {
            if (!array_key_exists($field, $raw)) {
                $errors[] = new IngestionError(
                    code:    IngestionErrorCode::ENVELOPE_FIELD_MISSING,
                    message: "Required field '{$field}' is missing.",
                    field:   $field,
                    traceId: $traceId,
                );
            }
        }

        // Validate field types (only if the field exists).
        $this->validateString($raw, 'source', $errors, $traceId, minLength: 1);
        $this->validateString($raw, 'type', $errors, $traceId, minLength: 1);
        $this->validatePayload($raw, $errors, $traceId);
        $this->validateTimestamp($raw, $errors, $traceId);
        $this->validateTraceId($raw, $errors, $traceId);
        $this->validateString($raw, 'tenant_id', $errors, $traceId, minLength: 1, optional: true);
        $this->validateMetadata($raw, $errors, $traceId);

        if ($errors !== []) {
            throw new InvalidEnvelopeException($errors, $traceId);
        }

        // Normalize: auto-generate trace_id if missing.
        $traceId ??= (string) Uuid::v4();

        return new Envelope(
            source:   (string) $raw['source'],
            type:     (string) $raw['type'],
            payload:  (array) $raw['payload'],
            timestamp: (string) $raw['timestamp'],
            traceId:  $traceId,
            tenantId: isset($raw['tenant_id']) ? (string) $raw['tenant_id'] : null,
            metadata: isset($raw['metadata']) && is_array($raw['metadata']) ? $raw['metadata'] : [],
        );
    }

    /** @param list<IngestionError> $errors */
    private function validateString(array $raw, string $field, array &$errors, ?string $traceId, int $minLength = 0, bool $optional = false): void
    {
        if (!array_key_exists($field, $raw)) {
            return; // Missing required fields are caught above.
        }

        if ($optional && $raw[$field] === null) {
            return;
        }

        if (!is_string($raw[$field])) {
            $errors[] = new IngestionError(
                code:    IngestionErrorCode::ENVELOPE_FIELD_TYPE_INVALID,
                message: "Field '{$field}' must be a string.",
                field:   $field,
                traceId: $traceId,
            );
            return;
        }

        if ($minLength > 0 && strlen(trim($raw[$field])) < $minLength) {
            $errors[] = new IngestionError(
                code:    IngestionErrorCode::ENVELOPE_FIELD_EMPTY,
                message: "Field '{$field}' must be a non-empty string.",
                field:   $field,
                traceId: $traceId,
            );
        }
    }

    /** @param list<IngestionError> $errors */
    private function validatePayload(array $raw, array &$errors, ?string $traceId): void
    {
        if (!array_key_exists('payload', $raw)) {
            return;
        }

        if (!is_array($raw['payload'])) {
            $errors[] = new IngestionError(
                code:    IngestionErrorCode::ENVELOPE_FIELD_TYPE_INVALID,
                message: "Field 'payload' must be an object.",
                field:   'payload',
                traceId: $traceId,
            );
        }
    }

    /** @param list<IngestionError> $errors */
    private function validateTimestamp(array $raw, array &$errors, ?string $traceId): void
    {
        if (!array_key_exists('timestamp', $raw)) {
            return;
        }

        if (!is_string($raw['timestamp'])) {
            $errors[] = new IngestionError(
                code:    IngestionErrorCode::ENVELOPE_TIMESTAMP_INVALID,
                message: "Field 'timestamp' must be an ISO 8601 date-time string.",
                field:   'timestamp',
                traceId: $traceId,
            );
            return;
        }

        // Validate ISO 8601 format.
        $parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $raw['timestamp']);
        if ($parsed === false) {
            // Also try the basic ISO 8601 format without offset (e.g. 2026-03-08T17:00:00).
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $raw['timestamp']);
        }

        if ($parsed === false) {
            $errors[] = new IngestionError(
                code:    IngestionErrorCode::ENVELOPE_TIMESTAMP_INVALID,
                message: "Field 'timestamp' is not a valid ISO 8601 date-time.",
                field:   'timestamp',
                traceId: $traceId,
            );
        }
    }

    /** @param list<IngestionError> $errors */
    private function validateTraceId(array $raw, array &$errors, ?string $traceId): void
    {
        if (!array_key_exists('trace_id', $raw)) {
            return; // Optional — will be auto-generated.
        }

        if (!is_string($raw['trace_id'])) {
            $errors[] = new IngestionError(
                code:    IngestionErrorCode::ENVELOPE_TRACE_ID_INVALID,
                message: "Field 'trace_id' must be a UUID string.",
                field:   'trace_id',
                traceId: $traceId,
            );
            return;
        }

        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/', $raw['trace_id'])) {
            $errors[] = new IngestionError(
                code:    IngestionErrorCode::ENVELOPE_TRACE_ID_INVALID,
                message: "Field 'trace_id' must be a valid lowercase UUID.",
                field:   'trace_id',
                traceId: $traceId,
            );
        }
    }

    /** @param list<IngestionError> $errors */
    private function validateMetadata(array $raw, array &$errors, ?string $traceId): void
    {
        if (!array_key_exists('metadata', $raw)) {
            return;
        }

        if (!is_array($raw['metadata'])) {
            $errors[] = new IngestionError(
                code:    IngestionErrorCode::ENVELOPE_FIELD_TYPE_INVALID,
                message: "Field 'metadata' must be an object.",
                field:   'metadata',
                traceId: $traceId,
            );
        }
    }
}
