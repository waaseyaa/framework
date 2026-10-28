<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel\Fixtures;

use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesPermissionsInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesRolesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\User\Role;
use Waaseyaa\User\RoleRepository;

/**
 * A provider whose roles() answer changes on every call: the first call
 * yields a catalogued grant, any later call would yield an UNCATALOGUED one.
 * The kernel must consult roles() exactly once, validate that result, and
 * serve that same validated repository everywhere (#2788).
 *
 * @internal Test fixture.
 */
final class StatefulRolesFixtureProvider extends ServiceProvider implements ProvidesRolesInterface, ProvidesPermissionsInterface
{
    public const string PERMISSION = 'curate fixtures';

    public static int $rolesCalls = 0;

    public static ?RoleRepository $beforeComposition = null;

    public static ?RoleRepository $atBoot = null;

    public function register(): void
    {
        self::$beforeComposition = $this->resolveOptional(RoleRepository::class);
    }

    public function boot(): void
    {
        $roles = $this->resolve(RoleRepository::class);
        assert($roles instanceof RoleRepository);
        self::$atBoot = $roles;
    }

    public function roles(): iterable
    {
        ++self::$rolesCalls;
        if (self::$rolesCalls === 1) {
            yield new Role('curator', 'Curator', [self::PERMISSION]);

            return;
        }

        yield new Role('curator', 'Curator (drifted)', ['walk through walls']);
    }

    public function permissions(): array
    {
        return [self::PERMISSION => ['title' => 'Curate fixtures', 'description' => '']];
    }
}
