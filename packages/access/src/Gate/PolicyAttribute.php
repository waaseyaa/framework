<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Gate;

/**
 * Marks a class as a Gate policy for a specific entity type.
 *
 * Place this attribute on policy classes so the Gate can resolve them
 * by convention. The entityType property maps the policy to the entity
 * type it governs.
 *
 * Example:
 *
 *     #[PolicyAttribute(entityType: 'node')]
 *     final class NodePolicy { ... }
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class PolicyAttribute
{
    /** @var string[] */
    public readonly array $entityTypes;

    /**
     * @param string|string[] $entityType One entity type ID or an array of them.
     */
    public function __construct(
        string|array $entityType,
    ) {
        $this->entityTypes = is_array($entityType) ? $entityType : [$entityType];
    }
}
