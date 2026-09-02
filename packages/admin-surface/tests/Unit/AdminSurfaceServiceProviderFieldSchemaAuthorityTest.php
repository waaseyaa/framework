<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AdminSurface\AdminSurfaceServiceProvider;
use Waaseyaa\Api\InternalFieldVisibilityPolicy;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Field\FieldSchemaAuthority;
use Waaseyaa\Field\FieldTypeManager;
use Waaseyaa\Field\Tests\Fixtures\ExtensionFieldTypeFixture;
use Waaseyaa\Foundation\Http\ControllerDispatcher;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Node\NodeAccessPolicy;
use Waaseyaa\Routing\WaaseyaaRouter;

/**
 * The generic admin host presents schemas through the kernel's field schema
 * authority (#2786 B1), so a downstream plugin admitted at boot renders in the
 * admin form; a provider composed without that authority refuses loudly rather
 * than silently narrowing to the built-in roster.
 */
#[CoversClass(AdminSurfaceServiceProvider::class)]
final class AdminSurfaceServiceProviderFieldSchemaAuthorityTest extends TestCase
{
    #[Test]
    public function schema_action_presents_registry_admitted_extension_types(): void
    {
        $fieldTypes = FieldTypeManager::fromManifest([
            'admin_money' => ExtensionFieldTypeFixture::declare('admin_money'),
        ]);
        $registry = new FieldDefinitionRegistry($fieldTypes);
        $registry->registerCoreFields('node', [
            'title' => new FieldDefinition('title', 'string', targetEntityTypeId: 'node', label: 'Title'),
            'type' => new FieldDefinition('type', 'string', targetEntityTypeId: 'node', label: 'Content type'),
            'price' => new FieldDefinition('price', 'admin_money', targetEntityTypeId: 'node', label: 'Price'),
        ]);

        $provider = new AdminSurfaceServiceProvider();
        $provider->setKernelServices($this->bus([
            FieldDefinitionRegistryInterface::class => $registry,
            EntityAccessHandler::class => new EntityAccessHandler([new NodeAccessPolicy()]),
            InternalFieldVisibilityPolicy::class => new InternalFieldVisibilityPolicy(),
            FieldSchemaAuthority::class => new FieldSchemaAuthority($fieldTypes),
        ]));

        $result = $this->schemaAction($provider, $registry);

        self::assertTrue($result['ok'], json_encode($result));
        self::assertSame('admin_money', $result['data']['properties']['price']['x-field-type'] ?? null);
    }

    #[Test]
    public function generic_host_refuses_to_compose_without_the_field_schema_authority(): void
    {
        $registry = new FieldDefinitionRegistry();
        $registry->registerCoreFields('node', [
            'title' => new FieldDefinition('title', 'string', targetEntityTypeId: 'node', label: 'Title'),
        ]);

        $provider = new AdminSurfaceServiceProvider();
        $provider->setKernelServices($this->bus([
            FieldDefinitionRegistryInterface::class => $registry,
            EntityAccessHandler::class => new EntityAccessHandler([new NodeAccessPolicy()]),
        ]));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('field schema authority');
        $provider->routes(new WaaseyaaRouter(), $this->entityTypeManager($registry));
    }

    /** @return array<string, mixed> */
    private function schemaAction(AdminSurfaceServiceProvider $provider, FieldDefinitionRegistry $registry): array
    {
        $router = new WaaseyaaRouter();
        $provider->routes($router, $this->entityTypeManager($registry));
        $route = $router->getRouteCollection()->get('admin_surface.action');
        self::assertNotNull($route);
        $controller = $route->getDefault('_controller');
        self::assertIsCallable($controller);

        $request = Request::create(
            '/admin/_surface/node/action/schema',
            'POST',
            content: json_encode([], JSON_THROW_ON_ERROR),
        );
        $request->attributes->set('_account', new AuthorizationPrincipal(
            42,
            true,
            [],
            ['administer content', 'administer nodes'],
            'operator',
        ));
        $request->attributes->set('_controller', $controller);
        $request->attributes->set('type', 'node');
        $request->attributes->set('action', 'schema');
        $response = new ControllerDispatcher([])->dispatch($request);

        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function entityTypeManager(FieldDefinitionRegistry $registry): EntityTypeManagerInterface
    {
        $definition = new EntityType(
            id: 'node',
            label: 'Content',
            class: AdminMoneySchemaTestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
        );
        $entityTypeManager = $this->createStub(EntityTypeManagerInterface::class);
        $entityTypeManager->method('hasDefinition')->willReturnCallback(static fn(string $type): bool => $type === 'node');
        $entityTypeManager->method('getDefinition')->willReturn($definition);
        $entityTypeManager->method('resolveFieldDefinitions')->willReturnCallback(
            fn(string $type, ?string $bundle = null): array => $registry->coreFieldsFor($type)
                + ($bundle === null ? [] : $registry->bundleFieldsFor($type, $bundle)),
        );

        return $entityTypeManager;
    }

    /** @param array<string, object> $services */
    private function bus(array $services): KernelServicesInterface
    {
        return new class ($services) implements KernelServicesInterface {
            /** @param array<string, object> $services */
            public function __construct(private readonly array $services) {}

            public function get(string $abstract): ?object
            {
                return $this->services[$abstract] ?? null;
            }
        };
    }
}

final class AdminMoneySchemaTestEntity extends ContentEntityBase
{
    public function __construct(array $values = [])
    {
        parent::__construct($values, 'node', ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type']);
    }
}
