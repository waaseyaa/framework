<?php

declare(strict_types=1);

namespace Waaseyaa\Note\Ingestion;

/**
 * Validates a raw ingestion envelope array against the core.note envelope spec v1.
 *
 * Returns a list of ValidationError — an empty list means the envelope is valid.
 *
 * Error codes:
 *   INVALID_ENVELOPE   — structural problems (missing/unsupported version, missing payload)
 *   MISSING_PROVENANCE — source or ingested_at absent or blank
 *   SCHEMA_VIOLATION   — payload fields violate core.note schema constraints
 */
final class IngestionEnvelopeValidator
{
    private const SUPPORTED_VERSIONS = ['1'];

    /**
     * @param array<string, mixed> $raw
     * @return ValidationError[]
     */
    public function validate(array $raw): array
    {
        $errors = [];

        $this->validateEnvelopeVersion($raw, $errors);
        $this->validateProvenance($raw, $errors);
        $this->validatePayload($raw, $errors);

        return $errors;
    }

    /** @param ValidationError[] $errors */
    private function validateEnvelopeVersion(array $raw, array &$errors): void
    {
        $version = $raw['envelope_version'] ?? null;

        if (!is_string($version) || !in_array($version, self::SUPPORTED_VERSIONS, true)) {
            $errors[] = new ValidationError(
                '/envelope_version',
                'INVALID_ENVELOPE',
                sprintf(
                    'envelope_version must be one of [%s]; got %s.',
                    implode(', ', self::SUPPORTED_VERSIONS),
                    json_encode($version),
                ),
            );
        }
    }

    /** @param ValidationError[] $errors */
    private function validateProvenance(array $raw, array &$errors): void
    {
        $source = $raw['source'] ?? null;
        if (!is_string($source) || trim($source) === '') {
            $errors[] = new ValidationError(
                '/source',
                'MISSING_PROVENANCE',
                'source is required and must be a non-empty string.',
            );
        }

        $ingestedAt = $raw['ingested_at'] ?? null;
        if (!is_string($ingestedAt) || trim($ingestedAt) === '') {
            $errors[] = new ValidationError(
                '/ingested_at',
                'MISSING_PROVENANCE',
                'ingested_at is required and must be a non-empty ISO 8601 timestamp string.',
            );
        }
    }

    /** @param ValidationError[] $errors */
    private function validatePayload(array $raw, array &$errors): void
    {
        $payload = $raw['payload'] ?? null;

        if (!is_array($payload)) {
            $errors[] = new ValidationError(
                '/payload',
                'INVALID_ENVELOPE',
                'payload is required and must be an object.',
            );
            return;
        }

        $title = $payload['title'] ?? null;
        if (!is_string($title) || trim($title) === '') {
            $errors[] = new ValidationError(
                '/payload/title',
                'SCHEMA_VIOLATION',
                'payload.title is required and must be a non-empty string.',
            );
        }

    }
}
