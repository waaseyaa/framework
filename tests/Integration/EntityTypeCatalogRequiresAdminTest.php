<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Routing\RequestContext;
use Waaseyaa\Access\AccessChecker;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Kernel\BuiltinRouteRegistrar;
use Waaseyaa\Routing\WaaseyaaRouter;

#[CoversNothing]
final class EntityTypeCatalogRequiresAdminTest extends TestCase
{
    #[Test]
    public function complete_entity_type_catalog_is_an_admin_only_operator_surface(): void
    {
        $router = new WaaseyaaRouter(new RequestContext('', 'GET'));
        new BuiltinRouteRegistrar(new EntityTypeManager(new EventDispatcher()))->register($router);
        $route = $router->getRouteCollection()->get('api.entity_types');
        self::assertNotNull($route);

        $checker = new AccessChecker();
        self::assertTrue($checker->check($route, $this->account(['anonymous'], false))->isForbidden());
        self::assertTrue($checker->check($route, $this->account(['authenticated'], true))->isForbidden());
        self::assertTrue($checker->check($route, $this->account(['admin'], true))->isAllowed());
    }

    /** @param list<string> $roles */
    private function account(array $roles, bool $authenticated): AccountInterface
    {
        return new class ($roles, $authenticated) implements AccountInterface {
            /** @param list<string> $roles */
            public function __construct(
                private readonly array $roles,
                private readonly bool $authenticated,
            ) {}

            public function id(): int
            {
                return $this->authenticated ? 1 : 0;
            }

            public function hasPermission(string $permission): bool
            {
                return false;
            }

            public function getRoles(): array
            {
                return $this->roles;
            }

            public function isAuthenticated(): bool
            {
                return $this->authenticated;
            }
        };
    }
}
