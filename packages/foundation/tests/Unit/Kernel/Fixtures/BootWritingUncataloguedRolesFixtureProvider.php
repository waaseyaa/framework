<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel\Fixtures;

use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesRolesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\User\Role;

/**
 * A provider whose boot() hook performs a durable write (the shape of the
 * generated governance provider seeding workflows) while granting a role
 * permission no catalogue declares. The kernel must refuse the role grant
 * BEFORE any provider boot hook runs, so this write never happens (#2788).
 *
 * @internal Test fixture.
 */
final class BootWritingUncataloguedRolesFixtureProvider extends ServiceProvider implements ProvidesRolesInterface
{
    public static ?string $markerPath = null;

    public function register(): void {}

    public function boot(): void
    {
        if (self::$markerPath !== null) {
            file_put_contents(self::$markerPath, "booted\n");
        }
    }

    public function roles(): iterable
    {
        yield new Role('phantom', 'Phantom', ['walk through walls']);
    }
}
