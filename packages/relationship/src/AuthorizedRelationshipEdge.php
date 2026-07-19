<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship;

/**
 * Immutable, principal-authorized relationship edge projection.
 *
 * Contains only the fixed traversal shape. It never exposes a Relationship
 * entity, raw value bag, field capability, or arbitrary selected field.
 *
 * @api
 */
final readonly class AuthorizedRelationshipEdge
{
    public function __construct(
        public string $relationshipId,
        public string $relationshipType,
        public string $direction,
        public bool $inverse,
        public string $relatedEntityType,
        public string $relatedEntityId,
        public string $relatedEntityLabel,
        public string $relatedEntityPath,
        public string $directionality,
        public ?float $weight,
        public ?float $confidence,
        public ?int $startDate,
        public ?int $endDate,
    ) {}
}
