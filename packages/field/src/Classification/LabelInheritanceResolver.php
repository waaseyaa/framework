<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Classification;

use Waaseyaa\Entity\EntityInterface;

/**
 * Resolves the effective classification label for an entity, applying
 * parent-inheritance when no explicit label is set.
 *
 * Resolution algorithm (per spec §FR-003):
 *   1. If the entity carries an explicit `classification_label` value,
 *      return a {@see ClassificationDecision::explicit()} decision.
 *   2. Otherwise, walk the parent chain via the registered
 *      {@see ClassificationParentResolverInterface} for the entity type.
 *   3. If a parent is found and carries a label, return
 *      {@see ClassificationDecision::inherited()} with the parent UUID.
 *   4. If no parent or parent has no label, return explicit(null).
 *
 * Inheritance is single-hop only: the entity inherits directly from its
 * immediate parent; the parent does not recursively resolve upward at
 * field-resolution time (to avoid unbounded DB traversal). Deep inheritance
 * must be materialised by saving parent entities in the correct order.
 *
 * @api
 */
final class LabelInheritanceResolver
{
    /** @var array<string, ClassificationParentResolverInterface> */
    private array $resolvers = [];

    /**
     * Register a parent resolver for a given entity type.
     *
     * Later calls for the same entity type ID replace the previous resolver.
     */
    public function addResolver(ClassificationParentResolverInterface $resolver): void
    {
        $this->resolvers[$resolver->getSupportedEntityTypeId()] = $resolver;
    }

    /**
     * Resolve the effective classification decision for the given entity.
     *
     * @param EntityInterface $entity         The entity being saved/resolved.
     * @param string|null     $previousLabel  The previously persisted label value
     *                                        (used to detect overrides).
     */
    public function resolve(EntityInterface $entity, ?string $previousLabel = null): ClassificationDecision
    {
        $entityTypeId = $entity->getEntityTypeId();

        // Read the explicitly-set label from the entity's field values.
        $explicitLabel = $this->readLabel($entity);

        // If the entity has an explicit label set, it's an explicit classification.
        // Detect whether this is an override of a previously inherited label.
        if ($explicitLabel !== null) {
            return ClassificationDecision::explicit($explicitLabel);
        }

        // No explicit label — try to inherit from parent.
        $resolver = $this->resolvers[$entityTypeId] ?? null;
        if ($resolver === null) {
            return ClassificationDecision::explicit(null);
        }

        $parent = $resolver->resolveParent($entity);
        if ($parent === null) {
            return ClassificationDecision::explicit(null);
        }

        $parentLabel = $this->readLabel($parent);
        $parentUuid = (string) ($parent->uuid() !== '' ? $parent->uuid() : $parent->id());

        if ($parentLabel === null) {
            return ClassificationDecision::explicit(null);
        }

        // Determine whether this is a fresh inheritance or an override.
        // An override occurs when the entity previously had an explicit label
        // but now has none (cleared to trigger inheritance again).
        $overriddenAt = ($previousLabel !== null && $previousLabel !== $parentLabel)
            ? new \DateTimeImmutable()->format(\DateTimeInterface::ATOM)
            : null;

        return ClassificationDecision::inherited(
            label: $parentLabel,
            parentUuid: $parentUuid,
            overriddenAt: $overriddenAt,
        );
    }

    // ---------------------------------------------------------------------------
    // Private helpers
    // ---------------------------------------------------------------------------

    private function readLabel(EntityInterface $entity): ?string
    {
        $raw = $entity->get('classification_label');
        if ($raw === null || $raw === '') {
            return null;
        }

        return (string) $raw;
    }
}
