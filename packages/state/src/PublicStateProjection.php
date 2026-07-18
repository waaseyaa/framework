<?php

declare(strict_types=1);

namespace Waaseyaa\State;

/**
 * Explicit identifier-plus-Public-values representation for state storage.
 * This type grants no Protected/Internal projection authority; callers must
 * use classification-aware projections introduced before activation.
 *
 * @api
 */
final readonly class PublicStateProjection
{
    /** @param array<string, scalar|array<array-key, mixed>|null> $publicValues */
    public function __construct(
        public string $entityTypeId,
        public string $entityId,
        public array $publicValues,
    ) {
        if ($entityTypeId === '' || $entityId === '' || !$this->containsOnlyData($publicValues)) {
            throw new \InvalidArgumentException('Public state projections require identifiers and recursively scalar/null values.');
        }
    }

    /** @return array{entity_type: string, entity_id: string, public_values: array<string, mixed>} */
    public function toArray(): array
    {
        return ['entity_type' => $this->entityTypeId, 'entity_id' => $this->entityId, 'public_values' => $this->publicValues];
    }

    private function containsOnlyData(array $values): bool
    {
        foreach ($values as $value) {
            if (is_array($value)) {
                if (!$this->containsOnlyData($value)) {
                    return false;
                }
            } elseif (!is_scalar($value) && $value !== null) {
                return false;
            }
        }

        return true;
    }
}
