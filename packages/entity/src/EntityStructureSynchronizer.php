<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

/** @internal Structural selectors only; never obtains content field values. */
final class EntityStructureSynchronizer
{
    /** @param list<string> $known */
    public static function languages(object $entity, string $active, string $default, array $known): void
    {
        if (!$entity instanceof EntityBase || !$entity->_hasEntityStructure()) {
            return;
        }

        $entity->_hydrateStructuralLanguages($active, $default, $known);
    }
}
