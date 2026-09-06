<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel\Fixtures;

use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesPermissionsInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesRolesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\User\Role;

/**
 * Root-application provider whose role grant is declared by its own
 * permission-catalogue contribution (#2788 G1).
 *
 * @internal Test fixture.
 */
final class CataloguedRolesFixtureProvider extends ServiceProvider implements ProvidesRolesInterface, ProvidesPermissionsInterface
{
    public const string PERMISSION = 'curate fixtures';

    public function register(): void {}

    public function roles(): iterable
    {
        yield new Role('curator', 'Curator', [self::PERMISSION]);
    }

    public function permissions(): array
    {
        return [self::PERMISSION => ['title' => 'Curate fixtures', 'description' => 'Curate the fixture library.']];
    }
}
