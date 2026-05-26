<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Attribute;

/**
 * Declares the data-governance profile of an {@see \Waaseyaa\AI\Tools\AgentToolInterface} implementation.
 *
 * Tools that expose only application metadata (entity types, routing, graph
 * introspection, spec content) and never user-data records MAY carry
 * `#[Capability(governedData: false)]` to opt out of the mandatory
 * {@see \Waaseyaa\Access\EntityAccessHandler} consultation per FR-003 / DIR-004.
 *
 * Tools WITHOUT this attribute (or with `governedData: true`) MUST consult
 * the access handler for every entity record touched during execution.
 *
 * The registry surfaces this as {@see \Waaseyaa\AI\Tools\AgentTool::$touchesGovernedData}.
 *
 * Enumeration test for opting out (apply to each tool under review):
 * "Does this tool's output expose entity-data-shaped content OR does its
 * side effect mutate governed user data?" If yes → governed (default).
 * If no (output is pure application-metadata) → `governedData: false`.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Capability
{
    public function __construct(
        public readonly bool $governedData = true,
    ) {}
}
