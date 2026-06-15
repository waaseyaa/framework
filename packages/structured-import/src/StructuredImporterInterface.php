<?php

declare(strict_types=1);

namespace Waaseyaa\StructuredImport;

/**
 * @api
 */
interface StructuredImporterInterface
{
    /**
     * Parse a payload and return the matched/unmatched/errors decomposition.
     *
     * Implementations are stateless across calls (no per-import mutation visible
     * outside the call frame).
     *
     * @param string $payload      Raw document content (UTF-8).
     * @param string $entityTypeId Target entity type for alias matching.
     * @param string|null $bundle  Target bundle; null means use entity_type as the implicit single bundle.
     */
    public function import(string $payload, string $entityTypeId, ?string $bundle = null): ImportResult;
}
