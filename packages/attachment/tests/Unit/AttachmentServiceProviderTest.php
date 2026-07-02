<?php

declare(strict_types=1);

namespace Waaseyaa\Attachment\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Attachment\AttachmentActiveGuardListener;
use Waaseyaa\Attachment\AttachmentServiceProvider;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;

/**
 * Mirrors RelationshipServiceProviderTest::boot_wires_delete_guard_to_pre_delete_event
 * (#1852) — the stub bus keys the dispatcher under the SAME
 * Symfony-contracts FQCN `ProviderRegistryKernelServices::get()` actually
 * serves it under in production. A stub keyed on the foundation FQCN would
 * mask a boot() that never resolves the dispatcher in a real kernel and
 * silently registers nothing — exactly the bug #1852 fixed for the
 * relationship delete guard.
 */
#[CoversClass(AttachmentServiceProvider::class)]
final class AttachmentServiceProviderTest extends TestCase
{
    #[Test]
    public function registersAttachmentEntityType(): void
    {
        $provider = new AttachmentServiceProvider();
        $provider->register();

        $entityTypes = $provider->getEntityTypes();

        $this->assertCount(1, $entityTypes);
        $this->assertSame('attachment', $entityTypes[0]->id());
    }

    #[Test]
    public function bootWiresActiveGuardListenerToPreSaveEvent(): void
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $database = DBALDatabase::createSqlite();

        $provider = new AttachmentServiceProvider();
        $provider->setKernelServices($this->kernelServices([
            \Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class => $dispatcher,
            DatabaseInterface::class => $database,
        ]));
        $provider->register();
        $provider->boot();

        $listeners = $dispatcher->getListeners(EntityEvents::PRE_SAVE->value);
        $this->assertNotEmpty($listeners, 'Active guard must subscribe to pre-save');
        $this->assertInstanceOf(AttachmentActiveGuardListener::class, $listeners[0]);
    }

    #[Test]
    public function bootWithoutDispatcherIsANoOp(): void
    {
        $provider = new AttachmentServiceProvider();
        $provider->setKernelServices($this->kernelServices([]));
        $provider->register();

        $provider->boot();
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function bootWithoutDatabaseIsANoOp(): void
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();

        $provider = new AttachmentServiceProvider();
        $provider->setKernelServices($this->kernelServices([
            \Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class => $dispatcher,
        ]));
        $provider->register();
        $provider->boot();

        $this->assertSame([], $dispatcher->getListeners(EntityEvents::PRE_SAVE->value));
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
