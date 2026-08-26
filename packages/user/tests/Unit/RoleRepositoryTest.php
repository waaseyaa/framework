<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
