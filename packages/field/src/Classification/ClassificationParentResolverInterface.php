<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Classification;

use Waaseyaa\Entity\EntityInterface;

/**
 * Contract for resolving the classification parent of an entity.
 *
 * Each content-type that supports label inheritance must provide an
 * implementation. Implementations are registered with
 * {@see LabelInheritanceResolver} keyed by entity-type ID.
 *
 * @api
 */
interface ClassificationParentResolverInterface
{
    /**
     * Return the entity-type ID this resolver handles.
     *
     * Must match the entity type ID registered in EntityTypeManager
     * (e.g. 'node', 'media', 'attachment').
     */
    public function getSupportedEntityTypeId(): string;

    /**
     * Resolve the parent entity for classification-label inheritance.
     *
     * Returns null when the entity has no parent or when the resolver cannot
     * determine the parent (no-parent is not an error condition).
     */
    public function resolveParent(EntityInterface $entity): ?EntityInterface;
}
