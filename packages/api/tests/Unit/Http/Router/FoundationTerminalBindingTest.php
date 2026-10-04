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
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Api\EntityTypeApiExposurePolicy;
use Waaseyaa\Api\InternalFieldVisibilityPolicy;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityRepository;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Api\Tests\Fixtures\TranslatableTestEntity;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Field\FieldSchemaAuthority;
use Waaseyaa\Field\FieldTypeManager;
use Waaseyaa\Field\FieldTypeManagerInterface;
use Waaseyaa\Foundation\Http\ControllerDispatcher;
use Waaseyaa\Foundation\Http\Router\JsonApiRouter;
use Waaseyaa\Foundation\Http\Router\SchemaRouter;
use Waaseyaa\Foundation\Http\Router\TranslationRouter;
use Waaseyaa\Foundation\Http\Router\WorkflowDefinitionsApiRouter;
use Waaseyaa\Foundation\Kernel\KernelHandlerContainer;
use Waaseyaa\Foundation\Routing\Metadata\HandlerReference;
use Waaseyaa\Foundation\Routing\Metadata\RouteDefinition;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Routing\Exception\HandlerResolutionException;
use Waaseyaa\Routing\RouteHandlerResolver;
use Waaseyaa\Testing\Database\TemporarySqliteDatabase;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;
use Waaseyaa\Workflows\Read\ActiveWorkflows;

#[CoversClass(ApiServiceProvider::class)]
#[CoversClass(JsonApiRouter::class)]
#[CoversClass(TranslationRouter::class)]
#[CoversClass(SchemaRouter::class)]
#[CoversClass(WorkflowDefinitionsApiRouter::class)]
final class FoundationTerminalBindingTest extends TestCase
{
    public function testExplicitBindingsAreDeferredAndNonshared(): void
    {
        [$manager, $access] = $this->world();
        [$provider, $bus] = $this->provider([EntityTypeManager::class => $manager, EntityAccessHandler::class => $access, FieldTypeManagerInterface::class => new FieldTypeManager()]);
        foreach ([JsonApiRouter::class => 'handle', TranslationRouter::class => 'handle', SchemaRouter::class => 'show', WorkflowDefinitionsApiRouter::class => 'list'] as $class => $action) {
            $bus->reads = [];
            $definition = $this->definition($class, $action);
            $request = new Request(attributes: ['_route' => $definition->name, '_controller' => $definition->handler->id]);
            $services = new KernelHandlerContainer([$provider], [])->explicitServices($request);
            self::assertTrue($services->has($class));
            self::assertSame([], $bus->reads);
            self::assertIsCallable(new RouteHandlerResolver($definition, $services)->resolveMatched());
            self::assertNotSame($provider->resolve($class), $provider->resolve($class));
        }
    }

    public function testSelectedDispatchPreservesLegacyReadsAndRefusals(): void
    {
        [$manager, $access] = $this->world();
        [$provider] = $this->provider([EntityTypeManager::class => $manager, EntityAccessHandler::class => $access, FieldTypeManagerInterface::class => new FieldTypeManager()]);
        $exposure = $provider->resolve(EntityTypeApiExposurePolicy::class);
        $visibility = $provider->resolve(InternalFieldVisibilityPolicy::class);
        $database = new TemporarySqliteDatabase();
        RuntimeSchemaMigrations::broadcast($database->database());
        $broadcast = new BroadcastStorage($database->database());
        try {
            foreach ([
                [JsonApiRouter::class, 'handle', 'GET', 'Waaseyaa\\Api\\JsonApiController::index', ['_entity_type' => 'article'], 200],
                [JsonApiRouter::class, 'handle', 'GET', 'Waaseyaa\\Api\\JsonApiController::show', ['_entity_type' => 'article', 'id' => '1'], 200],
                [JsonApiRouter::class, 'handle', 'GET', 'Waaseyaa\\Api\\JsonApiController::index', ['_entity_type' => 'missing'], 404],
                [JsonApiRouter::class, 'handle', 'GET', 'Waaseyaa\\Api\\JsonApiController::index', ['_entity_type' => 'hidden'], 404],
                [JsonApiRouter::class, 'handle', 'PATCH', 'Waaseyaa\\Api\\JsonApiController::update', ['_entity_type' => 'article', 'id' => '1'], 428],
                [JsonApiRouter::class, 'handle', 'DELETE', 'Waaseyaa\\Api\\JsonApiController::destroy', ['_entity_type' => 'article', 'id' => '1'], 428],
                [TranslationRouter::class, 'handle', 'GET', 'Waaseyaa\\Api\\Controller\\TranslationController::index', ['_entity_type' => 'article', 'id' => '1'], 200],
                [TranslationRouter::class, 'handle', 'GET', 'Waaseyaa\\Api\\Controller\\TranslationController::show', ['_entity_type' => 'article', 'id' => '1', 'langcode' => 'en'], 200],
                [TranslationRouter::class, 'handle', 'GET', 'Waaseyaa\\Api\\Controller\\TranslationController::show', ['_entity_type' => 'missing', 'id' => '1', 'langcode' => 'en'], 404],
                [SchemaRouter::class, 'show', 'GET', 'Waaseyaa\\Api\\Controller\\SchemaController::show', ['entity_type' => 'article'], 200],
                [SchemaRouter::class, 'show', 'GET', 'Waaseyaa\\Api\\Controller\\SchemaController::show', ['entity_type' => 'missing'], 404],
                [SchemaRouter::class, 'show', 'GET', 'Waaseyaa\\Api\\Controller\\SchemaController::show', ['entity_type' => 'hidden'], 404],
                [WorkflowDefinitionsApiRouter::class, 'list', 'GET', 'Waaseyaa\\Api\\Workflow\\WorkflowDefinitionsController::list', [], 200],
            ] as [$class, $action, $method, $legacyRef, $params, $status]) {
                $legacy = match ($class) {
                    JsonApiRouter::class => new JsonApiRouter($manager, $access, exposurePolicy: $exposure, internalFieldVisibility: $visibility),
                    TranslationRouter::class => new TranslationRouter($manager, $access, $exposure, $visibility),
                    SchemaRouter::class => new SchemaRouter($manager, $access, exposurePolicy: $exposure, fieldSchemaAuthority: new FieldSchemaAuthority(new FieldTypeManager())),
                    WorkflowDefinitionsApiRouter::class => new WorkflowDefinitionsApiRouter(),
                };
                $request = Request::create('/fixture', $method);
                $request->attributes->add($params + ['_controller' => $legacyRef, '_account' => new AuthorizationPrincipal(7, true, [], [], 'fixture'), '_broadcast_storage' => $broadcast]);
                $expected = new ControllerDispatcher([$legacy])->dispatch($request);
                $definition = $this->definition($class, $action);
                $request->attributes->add(['_route' => $definition->name, '_controller' => $definition->handler->id]);
                $services = new KernelHandlerContainer([$provider], [])->explicitServices($request);
                $callable = new RouteHandlerResolver($definition, $services)->resolveMatched();
                $request->attributes->set('_controller', $callable);
                $actual = new ControllerDispatcher([])->dispatch($request);
                self::assertSame($status, $actual->getStatusCode(), $class . ':' . $method);
                self::assertSame($expected->getStatusCode(), $actual->getStatusCode());
                self::assertSame($expected->getContent(), $actual->getContent());
                self::assertSame($expected->headers->get('Content-Type'), $actual->headers->get('Content-Type'));
                self::assertSame($expected->headers->get('ETag'), $actual->headers->get('ETag'));
                if (isset($params['id'])) {
                    self::assertSame($actual->getContent(), $callable($request, id: 'conflicting')->getContent());
                    if (isset($params['langcode'])) {
                        self::assertSame($actual->getContent(), $callable($request, id: 'conflicting', langcode: 'conflicting')->getContent());
                    }
                } elseif (isset($params['entity_type'])) {
                    self::assertSame($actual->getContent(), $callable($request, entity_type: 'conflicting')->getContent());
                }
            }
        } finally {
            $database->remove();
        }
    }

    public function testUnhealthyRequiredAndDeclaredOptionalBindingsRefuseSelection(): void
    {
        [$manager, $access] = $this->world();
        foreach ([JsonApiRouter::class, TranslationRouter::class, SchemaRouter::class, WorkflowDefinitionsApiRouter::class] as $class) {
            $keys = $class === WorkflowDefinitionsApiRouter::class ? [ActiveWorkflows::class] : [EntityTypeManager::class, EntityAccessHandler::class];
            if ($class === SchemaRouter::class) {
                $keys = array_merge($keys, [FieldDefinitionRegistryInterface::class, FieldSchemaAuthority::class, FieldTypeManagerInterface::class]);
            }
            foreach ($keys as $key) {
                $required = in_array($key, [EntityTypeManager::class, EntityAccessHandler::class, FieldTypeManagerInterface::class], true);
                foreach (array_merge([new \stdClass(), new \RuntimeException('private failure')], $required ? [null] : []) as $bad) {
                    [$provider, $bus] = $this->provider(array_replace([EntityTypeManager::class => $manager, EntityAccessHandler::class => $access, FieldTypeManagerInterface::class => new FieldTypeManager()], [$key => $bad]));
                    $action = $class === SchemaRouter::class ? 'show' : ($class === WorkflowDefinitionsApiRouter::class ? 'list' : 'handle');
                    $definition = $this->definition($class, $action);
                    $request = new Request(attributes: ['_route' => $definition->name, '_controller' => $definition->handler->id]);
                    $services = new KernelHandlerContainer([$provider], [])->explicitServices($request);
                    self::assertSame([], $bus->reads);
                    try {
                        new RouteHandlerResolver($definition, $services)->resolveMatched();
                        self::fail('Unhealthy execution binding must refuse.');
                    } catch (HandlerResolutionException $error) {
                        self::assertStringContainsString('resolution-failed', $error->getMessage());
                        self::assertStringNotContainsString('private', $error->getMessage());
                    }
                }
            }
        }
    }

    public function testDeclaredSchemaAuthorityAvoidsUnneededFallbackResolution(): void
    {
        [$manager, $access] = $this->world();
        [$provider, $bus] = $this->provider([
            EntityTypeManager::class => $manager,
            EntityAccessHandler::class => $access,
            FieldDefinitionRegistryInterface::class => $this->createStub(FieldDefinitionRegistryInterface::class),
            FieldSchemaAuthority::class => new FieldSchemaAuthority(new FieldTypeManager()),
            FieldTypeManagerInterface::class => new \RuntimeException('Unselected fallback must not execute.'),
        ]);
        self::assertInstanceOf(SchemaRouter::class, $provider->resolve(SchemaRouter::class));
        self::assertNotContains(FieldTypeManagerInterface::class, $bus->reads);
    }

    private function world(): array
    {
        $storage = new InMemoryEntityStorage();
        $keys = TranslatableTestEntity::definitionKeys();
        $storage->save(new TranslatableTestEntity(['id' => '1', 'title' => 'Fixture', 'type' => 'article', 'langcode' => 'en', 'default_langcode' => true], 'article', $keys));
        $repository = new InMemoryEntityRepository($storage);
        $manager = new EntityTypeManager(new EventDispatcher(), storageFactory: static fn() => $storage, repositoryFactory: static fn() => $repository);
        $manager->registerEntityType(new EntityType('article', 'Article', TranslatableTestEntity::class, keys: $keys, translatable: true, api: true));
        $manager->registerEntityType(new EntityType('hidden', 'Hidden', TranslatableTestEntity::class, keys: $keys));
        $access = new EntityAccessHandler([new class implements AccessPolicyInterface {
            public function appliesTo(string $entityTypeId): bool
            {
                return true;
            }
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }
            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }
        }]);
        return [$manager, $access];
    }

    private function definition(string $class, string $action): RouteDefinition
    {
        return new RouteDefinition('fixture.foundation', '/fixture', HandlerReference::fromString('class:' . $class . '::' . $action), sourceId: 'fixture.api', ordinal: 0);
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
