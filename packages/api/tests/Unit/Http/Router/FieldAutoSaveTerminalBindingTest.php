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
use Waaseyaa\Api\Controller\FieldAutoSaveController;
use Waaseyaa\Api\Http\Router\FieldAutoSaveApiRouter;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityRepository;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Field\FieldTypeManager;
use Waaseyaa\Foundation\Http\ControllerDispatcher;
use Waaseyaa\Foundation\Kernel\KernelHandlerContainer;
use Waaseyaa\Foundation\Routing\Metadata\HandlerReference;
use Waaseyaa\Foundation\Routing\Metadata\RouteDefinition;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Routing\Exception\HandlerResolutionException;
use Waaseyaa\Routing\RouteHandlerResolver;

#[CoversClass(ApiServiceProvider::class)]
#[CoversClass(FieldAutoSaveApiRouter::class)]
final class FieldAutoSaveTerminalBindingTest extends TestCase
{
    public function testSelectedRequestAdapterPreservesControllerRefusalsAndParameterAuthority(): void
    {
        foreach ([
            ['article', '1', 'title', 'text/plain', '{}', true, true, 415],
            ['article', '1', 'title', 'application/json', 'bad', true, true, 422],
            ['missing', '1', 'title', 'application/json', '{"value":"New"}', true, true, 404],
            [['invalid'], '1', 'title', 'application/json', '{"value":"New"}', true, true, 404],
            ['article', '1', 'title', 'application/json', '{"value":"New"}', false, true, 428],
            ['article', 'missing', 'title', 'application/json', '{"value":"New"}', true, true, 404],
            ['article', ['invalid'], 'title', 'application/json', '{"value":"New"}', true, true, 404],
            ['article', null, 'title', 'application/json', '{"value":"New"}', true, true, 404],
            ['article', '1', 'missing', 'application/json', '{"value":"New"}', true, true, 404],
            ['article', '1', ['invalid'], 'application/json', '{"value":"New"}', true, true, 404],
            ['article', '1', 'title', 'application/json', '{"value":"New"}', true, false, 403],
        ] as [$type, $id, $key, $media, $body, $fence, $allow, $status]) {
            [$manager, $access, $registry, $repository] = $this->world($allow);
            [$provider, $bus] = $this->provider([EntityTypeManager::class => $manager, EntityAccessHandler::class => $access, FieldDefinitionRegistryInterface::class => $registry]);
            $request = Request::create('/fixture', 'PUT', server: ['CONTENT_TYPE' => $media], content: $body);
            $request->attributes->add(['_entity_type' => $type, 'id' => $id, 'key' => $key, '_account' => new AuthorizationPrincipal(7, true, [], [], 'fixture')]);
            if ($fence) {
                $request->headers->set('If-Match', $repository->find('1')->mutationToken()->toStrongEtag());
            }
            $expected = new FieldAutoSaveController($manager, $access, $registry)->update($request, is_scalar($type) ? (string) $type : '', is_scalar($id) ? (string) $id : '', is_scalar($key) ? (string) $key : '');
            $callable = $this->select($provider, $request, $bus);
            $actual = new ControllerDispatcher([])->dispatch($request);
            self::assertSame($status, $actual->getStatusCode());
            self::assertSame($expected->getContent(), $actual->getContent());
            self::assertSame($expected->headers->get('Content-Type'), $actual->headers->get('Content-Type'));
            self::assertSame($actual->getContent(), $callable($request, id: 'conflicting', key: 'conflicting')->getContent());
            $adapter = $provider->resolve(FieldAutoSaveApiRouter::class);
            self::assertFalse($adapter->supports($request));
            $request->attributes->set('_controller', FieldAutoSaveController::class . '::update');
            self::assertTrue($adapter->supports($request));
            self::assertSame($expected->getContent(), $adapter->handle($request)->getContent());
            self::assertNotSame($provider->resolve(FieldAutoSaveApiRouter::class), $provider->resolve(FieldAutoSaveApiRouter::class));
        }
    }

    public function testSelectedUpdatePersistsAndReturnsTheRenewedCurrentToken(): void
    {
        [$manager, $access, $registry, $repository] = $this->world(true);
        [$provider, $bus] = $this->provider([EntityTypeManager::class => $manager, EntityAccessHandler::class => $access, FieldDefinitionRegistryInterface::class => $registry]);
        $request = Request::create('/fixture', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: '{"value":"New title"}');
        $before = $repository->find('1')->mutationToken();
        $request->headers->set('If-Match', $before->toStrongEtag());
        $request->attributes->add(['_entity_type' => 'article', 'id' => '1', 'key' => 'title', '_account' => new AuthorizationPrincipal(7, true, [], [], 'fixture')]);
        $this->select($provider, $request, $bus);
        $response = new ControllerDispatcher([])->dispatch($request);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $saved = $repository->find('1');
        self::assertSame('New title', $saved->get('title'));
        self::assertSame($before->aggregateVersion + 1, $saved->mutationToken()->aggregateVersion);
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($saved->mutationToken()->toOpaqueString(), $payload['data']['meta']['mutation_token']);
        self::assertSame($saved->mutationToken()->toStrongEtag(), $response->headers->get('ETag'));
        self::assertSame('New title', $payload['data']['attributes']['title']);
        self::assertSame(412, new ControllerDispatcher([])->dispatch($request)->getStatusCode());
    }

    public function testUnhealthyRequiredDependenciesRefuseSelection(): void
    {
        [$manager, $access, $registry] = $this->world(true);
        foreach ([EntityTypeManager::class, EntityAccessHandler::class, FieldDefinitionRegistryInterface::class] as $key) {
            foreach ([null, new \stdClass(), new \RuntimeException('private backend')] as $bad) {
                [$provider, $bus] = $this->provider(array_replace([EntityTypeManager::class => $manager, EntityAccessHandler::class => $access, FieldDefinitionRegistryInterface::class => $registry], [$key => $bad]));
                $request = new Request();
                try {
                    $this->select($provider, $request, $bus);
                    self::fail('Invalid binding must refuse.');
                } catch (HandlerResolutionException $error) {
                    self::assertStringContainsString('resolution-failed', $error->getMessage());
                    self::assertStringNotContainsString('private', $error->getMessage());
                }
            }
        }
    }

    public function testMissingPrincipalRefusesWithoutMutatingTheField(): void
    {
        [$manager, $access, $registry, $repository] = $this->world(true);
        [$provider, $bus] = $this->provider([EntityTypeManager::class => $manager, EntityAccessHandler::class => $access, FieldDefinitionRegistryInterface::class => $registry]);
        $request = Request::create('/fixture', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: '{"value":"New title"}');
        $request->headers->set('If-Match', $repository->find('1')->mutationToken()->toStrongEtag());
        $request->attributes->add(['_entity_type' => 'article', 'id' => '1', 'key' => 'title']);
        $expected = new FieldAutoSaveController($manager, $access, $registry)->update($request, 'article', '1', 'title');
        $this->select($provider, $request, $bus);
        $response = new ControllerDispatcher([])->dispatch($request);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame($expected->getContent(), $response->getContent());
        self::assertSame('Original', $repository->find('1')->get('title'));
    }

    private function select(ApiServiceProvider $provider, Request $request, object $bus): \Closure
    {
        $definition = new RouteDefinition('fixture.autosave', '/fixture/{id}/field/{key}', HandlerReference::fromString('class:Waaseyaa\\Api\\Http\\Router\\FieldAutoSaveApiRouter::update'), sourceId: 'fixture.api');
        $request->attributes->add(['_route' => $definition->name, '_controller' => $definition->handler->id]);
        $services = new KernelHandlerContainer([$provider], [])->explicitServices($request);
        self::assertTrue($services->has(FieldAutoSaveApiRouter::class));
        self::assertSame([], $bus->reads);
        $callable = new RouteHandlerResolver($definition, $services)->resolveMatched();
        $request->attributes->set('_controller', $callable);
        return $callable;
    }

    private function world(bool $allow): array
    {
        $storage = new InMemoryEntityStorage();
        $repository = new InMemoryEntityRepository($storage);
        $entity = new TestEntity(['id' => '1', 'title' => 'Original', 'type' => 'article'], 'article', TestEntity::definitionKeys());
        $repository->save($entity);
        $manager = new EntityTypeManager(new EventDispatcher(), storageFactory: static fn() => $storage, repositoryFactory: static fn() => $repository);
        $manager->registerEntityType(new EntityType('article', 'Article', TestEntity::class, keys: TestEntity::definitionKeys(), api: true));
        $access = new EntityAccessHandler([new class ($allow) implements AccessPolicyInterface {
            public function __construct(private bool $allow) {}
            public function appliesTo(string $entityTypeId): bool
            {
                return true;
            }
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return $this->allow ? AccessResult::allowed() : AccessResult::forbidden();
            }
            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }
        }]);
        $registry = new FieldDefinitionRegistry(new FieldTypeManager());
        $registry->registerBundleFields('article', 'article', [new FieldDefinition('title', 'string', targetEntityTypeId: 'article', targetBundle: 'article')]);
        return [$manager, $access, $registry, $repository];
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
