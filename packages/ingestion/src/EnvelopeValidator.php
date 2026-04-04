<?php

declare(strict_types=1);

namespace Waaseyaa\Ingestion;

/**
 * Base envelope validator with configurable required fields, versions, and entity types.
 *
 * Applications extend this to provide their supported versions, entity types,
 * and entity-specific validation rules via validateEntityData().
 *
 * @internal
 */
abstract class EnvelopeValidator implements PayloadValidatorInterface
{
    /**
     * @return list<string> Supported payload versions (e.g. ['1.0']).
     */
    abstract protected function supportedVersions(): array;

    /**
     * @return list<string> Supported entity type IDs.
     */
    abstract protected function supportedEntityTypes(): array;

    /**
     * Validate entity-specific data after envelope checks pass.
     *
     * @param array<string, mixed> $data The envelope's 'data' field.
     * @return list<string> Validation errors (empty = valid).
     */
    abstract protected function validateEntityData(string $entityType, array $data): array;

    /**
     * Required top-level envelope fields.
     *
     * Override to customize. Defaults match the standard ingest envelope.
     *
     * @return list<string>
     */
    protected function requiredFields(): array
    {
        return ['payload_id', 'version', 'source', 'snapshot_type', 'timestamp', 'entity_type', 'source_url', 'data'];
    }

    public function validate(array $envelope): ValidationResult
    {
        $errors = [];

        foreach ($this->requiredFields() as $field) {
            if (!array_key_exists($field, $envelope) || $envelope[$field] === '' || $envelope[$field] === null) {
                $errors[] = sprintf('Missing required field: %s', $field);
            }
        }

        if ($errors !== []) {
            return new ValidationResult($errors);
        }

        if (!in_array($envelope['version'], $this->supportedVersions(), true)) {
            $errors[] = sprintf('Unsupported version: %s', $envelope['version']);
        }

        if (!in_array($envelope['entity_type'], $this->supportedEntityTypes(), true)) {
            $errors[] = sprintf('Unsupported entity type: %s', $envelope['entity_type']);
        }

        if (!is_array($envelope['data'])) {
            $errors[] = 'Data field must be an array.';
            return new ValidationResult($errors);
        }

        if ($errors === []) {
            $errors = $this->validateEntityData((string) $envelope['entity_type'], $envelope['data']);
        }

        return new ValidationResult($errors);
    }
}
