<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\ServiceProvider\Capability;

/**
 * Provider capability: contributes permission definitions to the shared
 * permission catalogue.
 *
 * Implement this interface on a ServiceProvider to declare the permission
 * identifiers a package or application grants, alongside the entries a
 * `composer.json` `extra.waaseyaa.permissions` block declares. The kernel
 * collects every contribution into one id-keyed catalogue at boot, binds it
 * as `Waaseyaa\Access\PermissionHandlerInterface`, and refuses to boot when a
 * role contributed through {@see ProvidesRolesInterface} grants a permission
 * the catalogue does not know (#2788 G1). Duplicate ids fail closed:
 * authorization-bearing definitions have exactly one owner.
 *
 * Layer placement: Foundation (L0). The return shape is a plain array by
 * design to keep Foundation from importing the Access package (L1); the L1
 * collector (`PermissionHandler::fromProviders()`) validates it.
 *
 * @api
 */
interface ProvidesPermissionsInterface
{
    /**
     * The permission definitions provided by this service provider.
     *
     * Called exactly once per process boot during catalogue construction.
     * Implementations SHOULD be pure (no side effects, idempotent).
     *
     * @return array<string, array{title: string, description: string}> keyed by permission id
     */
    public function permissions(): array;
}
