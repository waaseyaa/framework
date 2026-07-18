<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Query;

use Waaseyaa\Access\Capability\QueryFieldOperation;

/** Metadata-only compiler input for a future non-public query operation. @api */
final readonly class QueryFieldReadRequest
{
    /** @param non-empty-list<string> $bundles @param non-empty-list<string> $fields @param non-empty-list<QueryFieldOperation> $operations */
    private function __construct(
        public string $entityTypeId,
        public array $bundles,
        public array $fields,
        public array $operations,
        public string $fingerprint,
    ) {}

    /**
     * Predicate values are accepted only as part of a normalized compiler
     * shape used to derive an irreversible fingerprint; the shape is not kept.
     *
     * @param list<string> $bundles Runtime validation narrows this to non-empty.
     * @param list<string> $fields Runtime validation narrows this to non-empty.
     * @param list<QueryFieldOperation> $operations Runtime validation narrows this to non-empty.
     * @param array<string, mixed> $normalizedShape
     */
    public static function fromShape(string $entityTypeId, array $bundles, array $fields, array $operations, array $normalizedShape): self
    {
        if ($entityTypeId === '' || $bundles === [] || $fields === [] || $operations === [] || $normalizedShape === []) {
            throw new \InvalidArgumentException('Query field-read requests require a complete non-empty shape.');
        }
        if (array_values(array_unique($bundles)) !== $bundles || array_values(array_unique($fields)) !== $fields || array_values(array_unique($operations, SORT_REGULAR)) !== $operations) {
            throw new \InvalidArgumentException('Query field-read request scopes must be unique.');
        }
        $encoded = json_encode($normalizedShape, JSON_THROW_ON_ERROR);
        return new self($entityTypeId, $bundles, $fields, $operations, hash('sha256', $encoded));
    }
}
