<?php

declare(strict_types=1);

namespace Waaseyaa\Routing\Tests\Unit;

use Waaseyaa\Routing\RouteBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RouteBuilder::class)]
final class RouteBuilderTest extends TestCase
{
    #[Test]
    public function buildCreatesRouteWithPath(): void
    {
        $route = RouteBuilder::create('/test')->build();

        $this->assertSame('/test', $route->getPath());
    }

    #[Test]
    public function controllerSetsDefault(): void
    {
        $route = RouteBuilder::create('/test')
            ->controller('App\\Controller\\TestController::index')
            ->build();

        $this->assertSame(
            'App\\Controller\\TestController::index',
            $route->getDefault('_controller')
        );
    }

    #[Test]
    public function methodsSetsHttpMethods(): void
    {
        $route = RouteBuilder::create('/test')
            ->methods('GET', 'POST')
            ->build();

        $this->assertSame(['GET', 'POST'], $route->getMethods());
    }

    #[Test]
    public function noMethodsAllowsAll(): void
    {
        $route = RouteBuilder::create('/test')->build();

        $this->assertSame([], $route->getMethods());
    }

    #[Test]
    public function entityParameterSetsParameterOption(): void
    {
        $route = RouteBuilder::create('/node/{node}')
            ->entityParameter('node', 'node')
            ->build();

        $parameters = $route->getOption('parameters');
        $this->assertSame(['type' => 'entity:node'], $parameters['node']);
    }

    #[Test]
    public function multipleEntityParameters(): void
    {
        $route = RouteBuilder::create('/node/{node}/author/{user}')
            ->entityParameter('node', 'node')
            ->entityParameter('user', 'user')
            ->build();

        $parameters = $route->getOption('parameters');
        $this->assertSame(['type' => 'entity:node'], $parameters['node']);
        $this->assertSame(['type' => 'entity:user'], $parameters['user']);
    }

    #[Test]
    public function requirePermissionSetsOption(): void
    {
        $route = RouteBuilder::create('/admin')
            ->requirePermission('administer site')
            ->build();

        $this->assertSame('administer site', $route->getOption('_permission'));
    }

    #[Test]
    public function requireRoleSetsOption(): void
    {
        $route = RouteBuilder::create('/admin')
            ->requireRole('administrator')
            ->build();

        $this->assertSame('administrator', $route->getOption('_role'));
    }

    #[Test]
    public function requireAuthenticationSetsOption(): void
    {
        $route = RouteBuilder::create('/api/node')
            ->requireAuthentication()
            ->build();

        $this->assertTrue($route->getOption('_authenticated'));
    }

    #[Test]
    public function allowAllSetsPublicOption(): void
    {
        $route = RouteBuilder::create('/public')
            ->allowAll()
            ->build();

        $this->assertTrue($route->getOption('_public'));
    }

    #[Test]
    public function renderSetsRenderOption(): void
    {
        $route = RouteBuilder::create('/')
            ->render()
            ->build();

        $this->assertTrue($route->getOption('_render'));
    }

    #[Test]
    public function requirementSetsRegex(): void
    {
        $route = RouteBuilder::create('/node/{id}')
            ->requirement('id', '\d+')
            ->build();

        $this->assertSame('\d+', $route->getRequirement('id'));
    }

    #[Test]
    public function defaultSetsDefaultValue(): void
    {
        $route = RouteBuilder::create('/items/{page}')
            ->default('page', '1')
            ->build();

        $this->assertSame('1', $route->getDefault('page'));
    }

    #[Test]
    public function fluentChaining(): void
    {
        $route = RouteBuilder::create('/node/{node}')
            ->controller('App\\Controller\\NodeController::view')
            ->methods('GET')
            ->entityParameter('node', 'node')
            ->requirePermission('access content')
            ->requirement('node', '\d+')
            ->default('_title', 'View Node')
            ->build();

        $this->assertSame('/node/{node}', $route->getPath());
        $this->assertSame('App\\Controller\\NodeController::view', $route->getDefault('_controller'));
        $this->assertSame(['GET'], $route->getMethods());
        $this->assertSame(['type' => 'entity:node'], $route->getOption('parameters')['node']);
        $this->assertSame('access content', $route->getOption('_permission'));
        $this->assertSame('\d+', $route->getRequirement('node'));
        $this->assertSame('View Node', $route->getDefault('_title'));
    }

    #[Test]
    public function createReturnsNewInstance(): void
    {
        $builder1 = RouteBuilder::create('/path1');
        $builder2 = RouteBuilder::create('/path2');

        $this->assertNotSame($builder1, $builder2);

        $route1 = $builder1->build();
        $route2 = $builder2->build();

        $this->assertSame('/path1', $route1->getPath());
        $this->assertSame('/path2', $route2->getPath());
    }
}
