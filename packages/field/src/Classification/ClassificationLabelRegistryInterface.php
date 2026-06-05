<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Classification;

use Waaseyaa\Field\Entity\ClassificationLabelDefinition;

/**
 * Lookup service that resolves a classification `label_id` to its
 * {@see ClassificationLabelDefinition} (display name, confidentiality level,
 * etc.).
 *
 * Used by access policies and retention jobs that need the definition's
 * confidentiality level to make a decision. Implementations are expected to
 * cache lookups per-request and invalidate on definition save/delete.
 *
 * @api
 */
interface ClassificationLabelRegistryInterface
{
    /**
     * Look up a label definition by its natural-key `label_id`.
     *
     * Returns null when the label is unknown — callers should treat an
     * unknown label as "no opinion" (neutral access) rather than as a
     * forbidden state, so a misconfigured policy never silently locks
     * everyone out.
     */
    public function definition(string $labelId): ?ClassificationLabelDefinition;

    /**
     * Clear the in-memory cache, forcing the next lookup to reload from
     * storage. Used by the lifecycle subscriber when a definition changes.
     */
    public function invalidate(): void;
}
