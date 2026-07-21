<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

/**
 * Marks a Protected field policy that composes its field opinion with the
 * authoritative hydrated-entity view decision.
 *
 * The field policy may still return Forbidden for a field-specific denial.
 * Neutral delegates release to the complete entity policy set. Evaluation
 * fails closed when the hydrated entity is unavailable or does not match the
 * immutable structure supplied to the field-read boundary.
 *
 * Entity policies participating in this path must make their view decision
 * from Public fields, declared policy-subject inputs, or contextual services;
 * reading the Protected field being decided would recurse.
 *
 * @internal
 */
interface EntityViewProtectedFieldReadPolicyInterface extends ProtectedFieldReadPolicyInterface {}
