<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\ServiceProvider\Capability;

/**
 * Provider capability: contributes user role definitions to the role registry.
 *
 * Implement this interface on a ServiceProvider to register one or more roles
 * with the framework. Each role carries the permission strings it grants, so
 * the user:assign-role command can stamp those permissions onto a user's flat
 * permissions array. Roles are collected at boot by iterating every provider
 * implementing this interface into a single id-keyed registry.
 *
 * Layer placement: Foundation (L0). The return type is the untyped `iterable`
 * by design to keep Foundation from importing the User package (L1). The
 * concrete element type is resolved by the L1 collector at runtime.
 *
 * @api
 */
interface ProvidesRolesInterface
{
    /**
     * Yield the role definitions provided by this service provider.
     *
     * Called exactly once per process boot during registry construction.
     * Implementations SHOULD be pure (no side effects, idempotent).
     *
     * @return iterable<\Waaseyaa\User\Role>
     */
    public function roles(): iterable;
}
