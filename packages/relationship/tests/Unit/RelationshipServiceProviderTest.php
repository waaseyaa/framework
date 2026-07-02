<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Foundation\Event\EventDispatcherInterface;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Relationship\RelationshipDeleteGuardListener;
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
    public function boot_wires_delete_guard_to_pre_delete_event(): void
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $entityTypeManager = new StubEntityTypeManager();

        $provider = new RelationshipServiceProvider();
        $provider->setKernelServices($this->kernelServices([
            EventDispatcherInterface::class => $dispatcher,
            EntityTypeManager::class => $entityTypeManager,
        ]));
        $provider->register();
        $provider->boot();

        $listeners = $dispatcher->getListeners(EntityEvents::PRE_DELETE->value);
        $this->assertNotEmpty($listeners, 'Delete guard must subscribe to pre-delete');
        $this->assertInstanceOf(RelationshipDeleteGuardListener::class, $listeners[0]);
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
}
