<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\Context\AccountFieldReadScopeInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Relationship\AuthorizedRelationshipTraversal;
use Waaseyaa\Relationship\RelationshipDeleteGuardListener;
use Waaseyaa\Relationship\RelationshipPreSaveListener;
use Waaseyaa\Relationship\RelationshipServiceProvider;
use Waaseyaa\Relationship\Tests\Fixtures\StubEntityTypeManager;

#[CoversClass(RelationshipServiceProvider::class)]
final class RelationshipServiceProviderTest extends TestCase
{
    #[Test]
    public function registers_relationship_entity_type(): void
    {
        $provider = new RelationshipServiceProvider();
        $provider->register();

        $entityTypes = $provider->getEntityTypes();

        $this->assertCount(1, $entityTypes);
        $this->assertSame('relationship', $entityTypes[0]->id());
    }

    #[Test]
    public function registers_fully_wired_authorized_traversal_facade(): void
    {
        $manager = new StubEntityTypeManager();
        $provider = new RelationshipServiceProvider();
        $provider->setKernelServices($this->kernelServices([
            EntityTypeManager::class => $manager,
            DatabaseInterface::class => DBALDatabase::createSqlite(),
            EntityAccessHandler::class => new EntityAccessHandler(),
            AccountFieldReadScopeInterface::class => new AccountFieldReadScope(),
        ]));
        $provider->register();

        self::assertInstanceOf(
            AuthorizedRelationshipTraversal::class,
            $provider->resolve(AuthorizedRelationshipTraversal::class),
        );
    }

    #[Test]
    public function boot_wires_delete_guard_to_pre_delete_event(): void
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $entityTypeManager = new StubEntityTypeManager();

        // The stub bus mirrors the PRODUCTION ProviderRegistryKernelServices
        // contract: the dispatcher is served ONLY under the Symfony-contracts
        // FQCN (the foundation FQCN resolves to null), the entity type
        // manager under EntityTypeManager::class. A stub keyed on the
        // foundation FQCN previously masked a boot() that never resolved the
        // dispatcher in a real kernel and silently registered nothing.
        $provider = new RelationshipServiceProvider();
        $provider->setKernelServices($this->kernelServices([
            \Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class => $dispatcher,
            EntityTypeManager::class => $entityTypeManager,
        ]));
        $provider->register();
        $provider->boot();

        $listeners = $dispatcher->getListeners(EntityEvents::PRE_DELETE->value);
        $this->assertNotEmpty($listeners, 'Delete guard must subscribe to pre-delete');
        $this->assertInstanceOf(RelationshipDeleteGuardListener::class, $listeners[0]);
    }

    #[Test]
    public function boot_wires_relationship_validation_to_the_production_pre_save_event(): void
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $entityTypeManager = new StubEntityTypeManager();
        $provider = new RelationshipServiceProvider();
        $provider->setKernelServices($this->kernelServices([
            \Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class => $dispatcher,
            EntityTypeManager::class => $entityTypeManager,
        ]));
        $provider->register();
        $provider->boot();

        $listeners = $this->listenersOfType($dispatcher, EntityEvents::PRE_SAVE->value, RelationshipPreSaveListener::class);
        $this->assertCount(1, $listeners, 'Relationship saves must be validated on the production lifecycle event exactly once');
    }

    #[Test]
    public function boot_twice_on_the_same_provider_registers_the_pre_save_listener_once(): void
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $entityTypeManager = new StubEntityTypeManager();
        $provider = new RelationshipServiceProvider();
        $provider->setKernelServices($this->kernelServices([
            \Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class => $dispatcher,
            EntityTypeManager::class => $entityTypeManager,
        ]));
        $provider->register();
        $provider->boot();
        $provider->boot();

        $preSave = $this->listenersOfType($dispatcher, EntityEvents::PRE_SAVE->value, RelationshipPreSaveListener::class);
        $preDelete = $this->listenersOfType($dispatcher, EntityEvents::PRE_DELETE->value, RelationshipDeleteGuardListener::class);
        $this->assertCount(1, $preSave);
        $this->assertCount(1, $preDelete);
    }

    #[Test]
    public function pre_save_listener_is_kernel_scoped_and_does_not_capture_an_account(): void
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $entityTypeManager = new StubEntityTypeManager();
        $provider = new RelationshipServiceProvider();
        $provider->setKernelServices($this->kernelServices([
            \Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class => $dispatcher,
            EntityTypeManager::class => $entityTypeManager,
        ]));
        $provider->register();
        $provider->boot();

        $listeners = $this->listenersOfType($dispatcher, EntityEvents::PRE_SAVE->value, RelationshipPreSaveListener::class);
        $this->assertCount(1, $listeners);
        $constructor = new \ReflectionClass(RelationshipPreSaveListener::class)->getConstructor();
        $this->assertNotNull($constructor);
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            $this->assertFalse(
                $type instanceof \ReflectionNamedType && str_contains($type->getName(), 'Account'),
                'RelationshipPreSaveListener must not capture a request principal',
            );
        }
        $validator = new \ReflectionProperty(RelationshipPreSaveListener::class, 'validator')->getValue($listeners[0]);
        $capturedManager = new \ReflectionProperty(\Waaseyaa\Relationship\RelationshipValidator::class, 'entityTypeManager')->getValue($validator);
        $this->assertSame($entityTypeManager, $capturedManager);
    }

    #[Test]
    public function boot_without_dispatcher_is_a_no_op(): void
    {
        $provider = new RelationshipServiceProvider();
        $provider->setKernelServices($this->kernelServices([]));
        $provider->register();

        $provider->boot();
        $this->addToAssertionCount(1);
    }

    /**
     * @param array<string, object> $services
     */
    private function kernelServices(array $services): KernelServicesInterface
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

    /**
     * @template T of object
     * @param class-string<T> $type
     * @return list<T>
     */
    private function listenersOfType(SymfonyEventDispatcherAdapter $dispatcher, string $eventName, string $type): array
    {
        $matched = [];
        foreach ($dispatcher->getListeners($eventName) as $listener) {
            if ($listener instanceof $type) {
                $matched[] = $listener;
            }
        }

        return $matched;
    }
}
