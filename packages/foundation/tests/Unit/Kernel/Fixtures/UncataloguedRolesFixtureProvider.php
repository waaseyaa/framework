<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel\Fixtures;

use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesRolesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\User\Role;

/**
 * Root-application provider granting a permission no catalogue declares
 * (#2788 G1): the kernel must refuse to boot.
 *
 * @internal Test fixture.
 */
final class UncataloguedRolesFixtureProvider extends ServiceProvider implements ProvidesRolesInterface
{
    public function register(): void {}

    public function roles(): iterable
    {
        yield new Role('phantom', 'Phantom', ['walk through walls']);
    }
}
