<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PermissionCatalogue\Fixtures;

use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesRolesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\User\Role;

/**
 * A root-application provider granting a permission no catalogue declares:
 * the kernel must refuse to boot rather than let `user:assign-role` stamp an
 * unknown permission string onto an account (#2788 G1).
 *
 * @internal Test fixture.
 */
final class UncataloguedRolesProvider extends ServiceProvider implements ProvidesRolesInterface
{
    public function register(): void {}

    public function roles(): iterable
    {
        yield new Role('ghost', 'Ghost', ['haunt the site']);
    }
}
