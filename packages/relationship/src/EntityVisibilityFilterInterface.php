<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship;

use Waaseyaa\Entity\EntityInterface;

/**
 * Visibility filter that owns a closed framework projection for sealed fields.
 *
 * @api
 */
interface EntityVisibilityFilterInterface extends VisibilityFilterInterface
{
    public function isEntityPublicForEntity(EntityInterface $entity): bool;
}
