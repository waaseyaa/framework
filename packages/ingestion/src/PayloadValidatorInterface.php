<?php

declare(strict_types=1);

namespace Waaseyaa\Ingestion;

/**
 * Validates an ingest payload envelope.
 *
 * Implementations check required fields, version compatibility,
 * entity type support, and entity-specific data constraints.
 *
 * @internal
 */
interface PayloadValidatorInterface
{
    /**
     * @param array<string, mixed> $envelope
     */
    public function validate(array $envelope): ValidationResult;
}
