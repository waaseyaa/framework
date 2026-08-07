<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Projection;

use Waaseyaa\Entity\EntityInterface;

/**
 * Canonical search document id for entity-backed documents: "type:id".
 *
 * The query-time entity candidate resolver parses exactly this shape (and
 * re-verifies it against the re-generated projection), so projectors MUST use
 * it. Because it derives from identity alone — no field reads — it is also
 * safe on the deletion path, where the entity may already be unreadable.
 *
 * @api
 */
final class EntitySearchDocumentId
{
    private function __construct() {}

    public static function fromEntity(EntityInterface $entity): ?string
    {
        $entityTypeId = $entity->getEntityTypeId();
        $id = $entity->id();
        if ($entityTypeId === '' || $id === null || $id === '') {
            return null;
        }

        return $entityTypeId . ':' . $id;
    }
}
