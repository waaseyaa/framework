<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\User\User;
use Waaseyaa\User\UserServiceProvider;

#[CoversClass(UserServiceProvider::class)]
final class UserServiceProviderTest extends TestCase
{
    #[Test]
    public function registers_user_entity_type(): void
    {
        $provider = new UserServiceProvider();
        $provider->register();

        $entityTypes = $provider->getEntityTypes();

        $this->assertCount(2, $entityTypes);
        $this->assertSame('user', $entityTypes[0]->id());
        $this->assertSame('User', $entityTypes[0]->getLabel());
        $this->assertSame(User::class, $entityTypes[0]->getClass());
    }

    #[Test]
    public function user_entity_type_has_field_definitions(): void
    {
        $provider = new UserServiceProvider();
        $provider->register();

        $fields = $provider->getEntityTypes()[0]->getFieldDefinitions();

        $this->assertArrayHasKey('mail', $fields);
        $this->assertArrayHasKey('status', $fields);
        $this->assertArrayHasKey('created', $fields);
        $this->assertSame('email', $fields['mail']['type']);
    }

    #[Test]
    public function user_entity_type_has_correct_keys(): void
    {
        $provider = new UserServiceProvider();
        $provider->register();

        $keys = $provider->getEntityTypes()[0]->getKeys();

        $this->assertSame('uid', $keys['id']);
        $this->assertSame('uuid', $keys['uuid']);
        $this->assertSame('name', $keys['label']);
    }

    #[Test]
    public function registers_auth_routes_owned_by_the_user_domain(): void
    {
        $provider = new UserServiceProvider();
        $router = new WaaseyaaRouter();

        $provider->routes($router);

        $routes = $router->getRouteCollection();
        $this->assertNotNull($routes->get('api.user.me'));
        $this->assertNotNull($routes->get('api.auth.login'));
        $this->assertNotNull($routes->get('api.auth.logout'));
    }
}
