<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Classification\ParentResolver;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Field\Classification\ClassificationParentResolverInterface;

/**
 * Stock parent resolver for 'node' entities.
 *
 * Nodes carry an optional `parent_id` field referencing another node.
 * Returns the parent node entity when that field is set.
 *
 * This is a stub resolver that reads `parent_id` directly from the entity's
 * field values. A full implementation (loading the parent entity from the
 * repository) is wired by the host application's ServiceProvider.
 *
 * @api
 */
final class NodeParentResolver implements ClassificationParentResolverInterface
{
    public function getSupportedEntityTypeId(): string
    {
        return 'node';
    }

    /**
     * Returns null — nodes do not carry a built-in typed parent pointer at the
     * framework level. Host applications that model node hierarchies should
     * replace this resolver with one that loads the parent via EntityRepository.
     */
    public function resolveParent(EntityInterface $entity): ?EntityInterface
    {
        // Node hierarchy is application-defined; no built-in parent pointer.
        return null;
    }
}
