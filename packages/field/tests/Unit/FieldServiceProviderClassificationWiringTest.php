<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Event\EntityEvent;
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
    public function classification_less_entity_saved_through_production_wiring_stays_unpolluted(): void
    {
        // With the subscriber live framework-wide (WP4), a PRE_SAVE dispatched
        // through the production-wired dispatcher for an entity type with zero
        // classification involvement must NOT write three stray NULL keys into
        // the entity's value bag — for sql-blob entities that bag IS the
        // `_data` column, so the pollution would reach every stored entity.
        $dispatcher = new SymfonyEventDispatcherAdapter();

        $provider = new FieldServiceProvider();
        $provider->setKernelServices($this->kernelServices([
            \Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class => $dispatcher,
            AuditWriterInterface::class => $this->createStub(AuditWriterInterface::class),
        ]));
        $provider->register();
        $provider->boot();

        $entity = $this->plainEntity('plain_widget', ['title' => 'hello']);
        $dispatcher->dispatch(new EntityEvent($entity), EntityEvents::PRE_SAVE->value);

        $stored = $entity->toArray();
        $this->assertArrayNotHasKey('classification_label', $stored);
        $this->assertArrayNotHasKey('classification_inherited_from', $stored);
        $this->assertArrayNotHasKey('classification_overridden_at', $stored);
    }

    #[Test]
    public function classified_entity_saved_through_production_wiring_keeps_label_and_provenance(): void
    {
        // Positive control for the pollution skip: a really-classified entity
        // must still get its keys written through the same production wiring.
        $dispatcher = new SymfonyEventDispatcherAdapter();

        $provider = new FieldServiceProvider();
        $provider->setKernelServices($this->kernelServices([
            \Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class => $dispatcher,
            AuditWriterInterface::class => $this->createStub(AuditWriterInterface::class),
        ]));
        $provider->register();
        $provider->boot();

        $entity = $this->plainEntity('node', ['classification_label' => 'confidential']);
        $dispatcher->dispatch(new EntityEvent($entity), EntityEvents::PRE_SAVE->value);

        $stored = $entity->toArray();
        $this->assertSame('confidential', $stored['classification_label']);
        $this->assertArrayHasKey('classification_inherited_from', $stored);
        $this->assertNull($stored['classification_inherited_from']);
        $this->assertArrayHasKey('classification_overridden_at', $stored);
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

    /** @param array<string, mixed> $fields */
    private function plainEntity(string $entityTypeId, array $fields): EntityInterface
    {
        return new class ($entityTypeId, $fields) implements EntityInterface {
            /** @param array<string, mixed> $fields */
            public function __construct(
                private readonly string $entityTypeId,
                private array $fields,
            ) {}

            public function id(): int|string|null
            {
                return 1;
            }
            public function uuid(): string
            {
                return 'plain-entity-uuid';
            }
            public function label(): string
            {
                return '';
            }
            public function getEntityTypeId(): string
            {
                return $this->entityTypeId;
            }
            public function bundle(): string
            {
                return $this->entityTypeId;
            }
            public function isNew(): bool
            {
                return true;
            }
            public function get(string $name): mixed
            {
                return $this->fields[$name] ?? null;
            }
            public function set(string $name, mixed $value): static
            {
                $this->fields[$name] = $value;

                return $this;
            }
            /** @return array<string, mixed> */
            public function toArray(): array
            {
                return $this->fields;
            }
            public function language(): string
            {
                return 'en';
            }
        };
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
