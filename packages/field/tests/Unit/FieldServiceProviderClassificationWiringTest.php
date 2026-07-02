<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Field\Classification\EntityLifecycleSubscriber;
use Waaseyaa\Field\FieldServiceProvider;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;

/**
 * Production-mirroring wiring test (#1852 pattern): the kernel-services bus
 * serves the dispatcher ONLY under the Symfony-contracts FQCN
 * (ProviderRegistryKernelServices::get()). FieldServiceProvider::boot()
 * previously resolved the foundation FQCN, which the bus never serves —
 * resolveOptional() returned null and the classification-label lifecycle
 * subscriber (docs/specs/classification-and-retention.md documents it as
 * running "on every save") never actually registered in a real kernel boot.
 */
#[CoversClass(FieldServiceProvider::class)]
final class FieldServiceProviderClassificationWiringTest extends TestCase
{
    #[Test]
    public function boot_wires_classification_lifecycle_subscriber_to_pre_save(): void
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $auditWriter = $this->createStub(AuditWriterInterface::class);

        $provider = new FieldServiceProvider();
        $provider->setKernelServices($this->kernelServices([
            \Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class => $dispatcher,
            AuditWriterInterface::class => $auditWriter,
        ]));
        $provider->register();
        $provider->boot();

        $listeners = $dispatcher->getListeners(EntityEvents::PRE_SAVE->value);
        $this->assertNotEmpty($listeners, 'Classification lifecycle subscriber must subscribe to pre-save');

        $found = false;
        foreach ($listeners as $listener) {
            $target = is_array($listener) ? $listener[0] : $listener;
            if ($target instanceof EntityLifecycleSubscriber) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'EntityLifecycleSubscriber must be among the pre-save listeners');
    }

    #[Test]
    public function boot_without_dispatcher_is_a_no_op(): void
    {
        $provider = new FieldServiceProvider();
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
