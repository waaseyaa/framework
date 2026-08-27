<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

/**
 * Canonical revision-id extraction for both revision contracts.
 *
 * {@see RevisionableInterface::getRevisionId()} is int-or-null.
 * {@see RevisionableEntityInterface::revisionId()} may be an int or a
 * digit string after SQL hydration. Callers that compared those copies
 * independently observed guaranteed conflicts on string-hydrated ids.
 *
 * @api
 */
final class RevisionId
{
    public static function of(EntityInterface $entity): ?int
    {
        $raw = match (true) {
            $entity instanceof RevisionableInterface => $entity->getRevisionId(),
            $entity instanceof RevisionableEntityInterface => $entity->revisionId(),
            \method_exists($entity, 'getRevisionId') => $entity->getRevisionId(),
            default => null,
        };

        if (\is_int($raw) && $raw > 0) {
            return $raw;
        }
        if (\is_string($raw) && \ctype_digit($raw)) {
            $id = (int) $raw;

            return $id > 0 ? $id : null;
        }

        return null;
    }
}
