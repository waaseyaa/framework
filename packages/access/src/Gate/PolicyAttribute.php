<?php

declare(strict_types=1);

namespace Aurora\Access\Gate;

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
    public function __construct(
        public readonly string $entityType,
    ) {}
}
