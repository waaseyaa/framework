<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit\Http\AppController;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Waaseyaa\Access\Gate\GateInterface;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Foundation\Http\HttpServiceResolverInterface;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\SSR\Http\AppController\AppControllerMethodInvoker;
use Waaseyaa\SSR\Http\AppController\AppInvocationContext;
use Waaseyaa\User\AnonymousUser;

#[ContentEntityType(id: 'bound_fixture')]
final class BoundFixtureEntity extends EntityBase
{
    protected string $entityTypeId = 'bound_fixture';

    public function __construct()
    {
        parent::__construct(['id' => 7, 'label' => 'Hidden'], entityKeys: ['id' => 'id', 'label' => 'label']);
    }
}

final class BoundFixtureController
{
    public function show(BoundFixtureEntity $entity): BoundFixtureEntity
    {
        return $entity;
    }
}

final class CustomMethodService {}

final class CustomServiceController
{
    public function show(CustomMethodService $service): CustomMethodService
    {
        return $service;
    }
}

#[CoversClass(AppControllerMethodInvoker::class)]
final class AppControllerMethodInvokerTest extends TestCase
{
    public function test_entity_binding_collapses_access_denial_to_not_found(): void
    {
        $entity = new BoundFixtureEntity();
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects(self::once())->method('find')->with('7')->willReturn($entity);
        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->expects(self::once())->method('getRepository')->with('bound_fixture')->willReturn($repository);
        $gate = $this->createMock(GateInterface::class);
        $gate->expects(self::once())->method('allows')->with(GateInterface::VIEW, $entity, self::isInstanceOf(AnonymousUser::class))->willReturn(false);

        $route = RouteBuilder::create('/fixture/{entity}')
            ->entityParameter('entity', 'bound_fixture')
            ->build();

        $this->expectException(ResourceNotFoundException::class);
        $this->invoker()->invoke(
            new BoundFixtureController(),
            'show',
            $route,
            'fixture.hidden',
            $this->context($route, $manager, $gate, ['entity' => '7']),
            strict: true,
            gate: $gate,
            serviceResolver: null,
            customResolvers: [],
        );
    }

    public function test_entity_binding_accepts_kernel_upcast_after_view_gate(): void
    {
        $entity = new BoundFixtureEntity();
        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->expects(self::never())->method('getRepository');
        $gate = $this->createMock(GateInterface::class);
        $gate->expects(self::once())->method('allows')->with(GateInterface::VIEW, $entity, self::isInstanceOf(AnonymousUser::class))->willReturn(true);
        $route = RouteBuilder::create('/fixture/{entity}')
            ->entityParameter('entity', 'bound_fixture')
            ->build();

        $result = $this->invoker()->invoke(
            new BoundFixtureController(),
            'show',
            $route,
            'fixture.visible',
            $this->context($route, $manager, $gate, ['entity' => $entity]),
            strict: true,
            gate: $gate,
            serviceResolver: null,
            customResolvers: [],
        );

        self::assertSame($entity, $result);
    }

    public function test_custom_method_service_uses_resolver_interface(): void
    {
        $service = new CustomMethodService();
        $resolver = new class ($service) implements HttpServiceResolverInterface {
            public function __construct(private readonly CustomMethodService $service) {}

            public function resolve(string $className): ?object
            {
                return $className === CustomMethodService::class ? $this->service : null;
            }
        };
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $route = RouteBuilder::create('/fixture')->build();
        $context = $this->context($route, $manager, null, [], $resolver);

        $result = $this->invoker()->invoke(
            new CustomServiceController(),
            'show',
            $route,
            'fixture.service',
            $context,
            strict: true,
            gate: null,
            serviceResolver: $resolver,
            customResolvers: [],
        );

        self::assertSame($service, $result);
    }

    private function invoker(): AppControllerMethodInvoker
    {
        return new AppControllerMethodInvoker();
    }

    /** @param array<string, mixed> $routeParams */
    private function context(
        \Symfony\Component\Routing\Route $route,
        EntityTypeManagerInterface $manager,
        ?GateInterface $gate,
        array $routeParams,
        ?HttpServiceResolverInterface $resolver = null,
    ): AppInvocationContext {
        return new AppInvocationContext(
            request: Request::create('/fixture'),
            route: $route,
            account: new AnonymousUser(),
            decisionAccount: new AnonymousUser(),
            entityTypeManager: $manager,
            twig: new Environment(new ArrayLoader([])),
            routeParams: $routeParams,
            query: [],
            gate: $gate,
            serviceResolver: $resolver,
        );
    }
}
