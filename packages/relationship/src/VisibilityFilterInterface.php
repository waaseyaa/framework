<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship;

interface VisibilityFilterInterface
{
    /**
     * @param array<string, mixed> $values
     */
    public function isEntityPublic(string $entityType, array $values): bool;
}
