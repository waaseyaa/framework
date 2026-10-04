<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\ApiServiceProvider;
use Waaseyaa\Api\Audit\AuditQueryReadModelInterface;
use Waaseyaa\Api\Controller\WorkflowTransitionController;
use Waaseyaa\Api\Http\Router\WorkflowTransitionApiRouter;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityRepository;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Config\ConfigInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\ControllerDispatcher;
use Waaseyaa\Foundation\Kernel\KernelHandlerContainer;
use Waaseyaa\Foundation\Routing\Metadata\HandlerReference;
use Waaseyaa\Foundation\Routing\Metadata\RouteDefinition;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Routing\Exception\HandlerResolutionException;
use Waaseyaa\Routing\RouteHandlerResolver;
use Waaseyaa\Workflows\Binding\WorkflowBindingResolver;
use Waaseyaa\Workflows\Transition\TransitionService;

#[CoversClass(ApiServiceProvider::class)]
#[CoversClass(WorkflowTransitionApiRouter::class)]
final class WorkflowTerminalBindingTest extends TestCase
{
    public function testReadAndWriteRefusalsPreserveRequestAuthorityAndOpaqueAccess(): void
    {
        [$manager, $transition, $access] = $this->world();
        $principal = new AuthorizationPrincipal(7, true, ['authenticated'], [], 'fixture');
        foreach ([
            ['transitions', '1', null, $access, '', 401],
            ['transition', '1', null, $access, '', 401],
            ['transitions', 'missing', $principal, $access, '', 404],
            ['transitions', ['invalid'], $principal, $access, '', 404],
            ['transition', null, $principal, $access, '', 404],
            ['transitions', '1', $principal, null, '', 404],
            ['transitions', '1', $principal, $access, '', 200],
            ['transition', '1', $principal, $access, 'invalid', 400],
        ] as [$action, $id, $account, $handler, $body, $status]) {
            [$provider, $bus] = $this->provider([EntityTypeManager::class => $manager, TransitionService::class => $transition, EntityAccessHandler::class => $handler]);
            $legacy = new WorkflowTransitionApiRouter(new WorkflowTransitionController($manager, $handler, $transition));
            $request = new Request(attributes: ['_entity_type' => 'article', 'id' => $id, '_account' => $account, '_route_params' => ['id' => 'conflicting']], content: $body);
            $request->attributes->set('_controller', WorkflowTransitionController::class . '::' . $action);
            $expected = new ControllerDispatcher([$legacy])->dispatch($request);
            $definition = $this->definition($action);
            $request->attributes->set('_route', $definition->name);
            $request->attributes->set('_controller', $definition->handler->id);
            $services = new KernelHandlerContainer([$provider], [])->explicitServices($request);
            self::assertTrue($services->has(WorkflowTransitionApiRouter::class));
            self::assertSame([], $bus->reads);
            $resolved = new RouteHandlerResolver($definition, $services)->resolveMatched();
            $request->attributes->set('_controller', $resolved);
            $actual = new ControllerDispatcher([])->dispatch($request);
            self::assertSame($status, $actual->getStatusCode());
            self::assertSame($expected->getStatusCode(), $actual->getStatusCode());
            self::assertSame($expected->getContent(), $actual->getContent());
            self::assertSame($expected->headers->get('Content-Type'), $actual->headers->get('Content-Type'));
            self::assertNotSame($provider->resolve(WorkflowTransitionApiRouter::class), $provider->resolve(WorkflowTransitionApiRouter::class));
        }
    }

    public function testRequiredAndUnhealthyOptionalBindingsRefuseSelectedConstruction(): void
    {
        [$manager, $transition, $access] = $this->world();
        foreach ([EntityTypeManager::class, TransitionService::class, EntityAccessHandler::class, AuditQueryReadModelInterface::class] as $key) {
            foreach (array_merge([new \stdClass(), new \RuntimeException('private backend detail')], in_array($key, [EntityTypeManager::class, TransitionService::class], true) ? [null] : []) as $bad) {
                [$provider, $bus] = $this->provider(array_replace([EntityTypeManager::class => $manager, TransitionService::class => $transition, EntityAccessHandler::class => $access], [$key => $bad]));
                $definition = $this->definition('transitions');
                $request = new Request(attributes: ['_route' => $definition->name, '_controller' => $definition->handler->id]);
                $services = new KernelHandlerContainer([$provider], [])->explicitServices($request);
                self::assertTrue($services->has(WorkflowTransitionApiRouter::class));
                self::assertSame([], $bus->reads);
                try {
                    new RouteHandlerResolver($definition, $services)->resolveMatched();
                    self::fail('Unhealthy bindings must refuse.');
                } catch (HandlerResolutionException $error) {
                    self::assertStringContainsString('resolution-failed', $error->getMessage());
                    self::assertStringNotContainsString('private', $error->getMessage());
                }
            }
        }
    }

    public function testExplicitCallableArgumentCannotReplaceMatchedId(): void
    {
        [$manager, $transition, $access] = $this->world();
        [$provider] = $this->provider([EntityTypeManager::class => $manager, TransitionService::class => $transition, EntityAccessHandler::class => $access]);
        $definition = $this->definition('transitions');
        $request = new Request(attributes: ['_entity_type' => 'article', 'id' => '1', '_account' => new AuthorizationPrincipal(7, true, ['authenticated'], [], 'fixture'), '_route' => $definition->name, '_controller' => $definition->handler->id]);
        $services = new KernelHandlerContainer([$provider], [])->explicitServices($request);
        $callable = new RouteHandlerResolver($definition, $services)->resolveMatched();
        self::assertSame(200, $callable($request, id: 'missing')->getStatusCode());
    }

    private function world(): array
    {
        $storage = new InMemoryEntityStorage();
        $storage->save(new TestEntity(['id' => '1', 'title' => 'Fixture', 'type' => 'article'], 'article', TestEntity::definitionKeys()));
        $repository = new InMemoryEntityRepository($storage);
        $manager = new EntityTypeManager(new EventDispatcher(), repositoryFactory: static fn() => $repository);
        $manager->registerEntityType(new EntityType('article', 'Article', TestEntity::class, keys: TestEntity::definitionKeys()));
        $config = $this->createStub(ConfigInterface::class);
        $config->method('getRawData')->willReturn([]);
        $factory = $this->createStub(ConfigFactoryInterface::class);
        $factory->method('get')->willReturn($config);
        $transition = new TransitionService(new WorkflowBindingResolver($factory, $manager), $manager);
        $access = new EntityAccessHandler([new class implements AccessPolicyInterface {
            public function appliesTo(string $entityTypeId): bool { return true; }
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult { return AccessResult::allowed(); }
            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult { return AccessResult::allowed(); }
        }]);
        return [$manager, $transition, $access];
    }

    private function definition(string $action): RouteDefinition
    {
        return new RouteDefinition('fixture.workflow', '/fixture/{id}', HandlerReference::fromString('class:' . WorkflowTransitionApiRouter::class . '::' . $action), sourceId: 'fixture.api', ordinal: 0);
    }

    private function provider(array $bindings): array
    {
        $bus = new class ($bindings) implements KernelServicesInterface {
            public array $reads = [];
            public function __construct(private array $bindings) {}
            public function get(string $abstract): ?object
            {
                $this->reads[] = $abstract;
                $value = $this->bindings[$abstract] ?? null;
                if ($value instanceof \Throwable) {
                    throw $value;
                }
                return $value;
            }
        };
        $provider = new ApiServiceProvider();
        $provider->setKernelServices($bus);
        $provider->register();
        $bus->reads = [];
        return [$provider, $bus];
    }
}
