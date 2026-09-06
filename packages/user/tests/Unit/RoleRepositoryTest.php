<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\PermissionHandler;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesRolesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\User\Role;
use Waaseyaa\User\RoleRepository;

#[CoversClass(RoleRepository::class)]
final class RoleRepositoryTest extends TestCase
{
    #[Test]
    public function duplicate_provider_role_ids_fail_closed_instead_of_using_provider_order(): void
    {
        $providers = [
            $this->provider(new Role('member', 'Member', ['view dashboard'])),
            $this->provider(new Role('member', 'Different member', ['administer users'])),
        ];

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Role "member" is registered more than once');
        RoleRepository::fromProviders($providers);
    }

    #[Test]
    public function assert_permissions_catalogued_passes_when_every_role_permission_is_known(): void
    {
        $repository = RoleRepository::fromProviders([
            $this->provider(new Role('editor', 'Editor', ['edit article', 'view article'])),
        ]);
        $catalogue = new PermissionHandler();
        $catalogue->registerPermission('edit article', 'Edit');
        $catalogue->registerPermission('view article', 'View');

        $repository->assertPermissionsCatalogued($catalogue);

        self::assertSame(['editor'], $repository->ids());
    }

    #[Test]
    public function assert_permissions_catalogued_fails_closed_naming_every_unknown_role_permission(): void
    {
        $repository = RoleRepository::fromProviders([
            $this->provider(new Role('editor', 'Editor', ['edit article', 'publish article'])),
            $this->provider(new Role('viewer', 'Viewer', ['view article'])),
        ]);
        $catalogue = new PermissionHandler();
        $catalogue->registerPermission('view article', 'View');

        try {
            $repository->assertPermissionsCatalogued($catalogue);
            self::fail('Expected a LogicException.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('Role "editor" grants permission "edit article"', $exception->getMessage());
            self::assertStringContainsString('Role "editor" grants permission "publish article"', $exception->getMessage());
            self::assertStringNotContainsString('"viewer"', $exception->getMessage());
        }
    }

    private function provider(Role $role): ServiceProvider&ProvidesRolesInterface
    {
        return new class ($role) extends ServiceProvider implements ProvidesRolesInterface {
            public function __construct(private readonly Role $role) {}

            public function register(): void {}

            public function roles(): iterable
            {
                yield $this->role;
            }
        };
    }
}
