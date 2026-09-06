<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PermissionCatalogue\Fixtures;

use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesPermissionsInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesRolesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\User\Role;

/**
 * A root-application provider whose role permissions are all declared through
 * the same provider's permission catalogue contribution (#2788 G1).
 *
 * @internal Test fixture.
 */
final class CataloguedRolesProvider extends ServiceProvider implements ProvidesRolesInterface, ProvidesPermissionsInterface
{
    public const string PERMISSION = 'review submissions';

    public function register(): void {}

    public function roles(): iterable
    {
        yield new Role('reviewer', 'Reviewer', [self::PERMISSION]);
    }

    public function permissions(): array
    {
        return [self::PERMISSION => ['title' => 'Review submissions', 'description' => 'Review submitted content.']];
    }
}
