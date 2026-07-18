<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship;

/** Exact compiled relationship endpoints with no unrelated entity values. @api */
final readonly class RelationshipTopology
{
    public function __construct(
        public string $fromType,
        public string $fromId,
        public string $toType,
        public string $toId,
    ) {}
}
