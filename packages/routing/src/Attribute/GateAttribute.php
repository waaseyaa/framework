<?php

declare(strict_types=1);

namespace Waaseyaa\Routing\Attribute;

/**
 * PHP attribute for declaring a gate ability on a controller method or class.
 *
 * Apply to controller methods to document the intended gate requirement:
 *
 *     #[GateAttribute('config.export')]
 *     public function export(): Response { ... }
 *
 * The ability string follows the convention "domain.action", which the Gate
 * system resolves to a Policy class (e.g., ConfigPolicy::export()).
 *
 * IMPORTANT: This attribute is a DECLARATIVE MARKER ONLY. The framework does
 * NOT scan controller attributes during route compilation, so applying
 * #[GateAttribute] to a method does NOT by itself enforce anything at request
 * time. A route relying solely on this attribute is NOT protected.
 *
 * To enforce a gate on a route, use one of:
 *   - {@see \Waaseyaa\Routing\RouteBuilder::gate()} (programmatic, preferred)
 *   - {@see \Waaseyaa\Access\AccessChecker::applyGateToRoute()} (manual transfer)
 *
 * @api
 * @deprecated Not auto-enforced; apply gates via RouteBuilder::gate() or
 *             AccessChecker::applyGateToRoute().
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
