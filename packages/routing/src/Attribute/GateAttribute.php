<?php

declare(strict_types=1);

namespace Aurora\Routing\Attribute;

/**
 * PHP attribute for route-level access control via the Gate system.
 *
 * Apply to controller methods to enforce ability checks:
 *
 *     #[GateAttribute('config.export')]
 *     public function export(): Response { ... }
 *
 * The ability string follows the convention "domain.action", which the Gate
 * system resolves to a Policy class (e.g., ConfigPolicy::export()).
 *
 * When used on a route, the AccessChecker reads this attribute and calls
 * the Gate to verify the current user is authorized for the declared ability.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class GateAttribute
{
    /**
     * @param string $ability The gate ability to check (e.g., 'config.export').
     * @param mixed $subject Optional subject for the gate check (e.g., an entity type string).
     */
    public function __construct(
        public readonly string $ability,
        public readonly mixed $subject = null,
    ) {}
}
