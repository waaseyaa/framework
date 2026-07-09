<?php

declare(strict_types=1);

namespace Waaseyaa\Node\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Node\Listener\NodeRevisionDefaultListener;
use Waaseyaa\Node\Node;
use Waaseyaa\Node\NodeServiceProvider;
use Waaseyaa\Node\NodeType;

#[CoversClass(NodeServiceProvider::class)]
final class NodeServiceProviderTest extends TestCase
{
    #[Test]
    public function registers_node_and_node_type(): void
    {
        $provider = new NodeServiceProvider();
        $provider->register();

        $entityTypes = $provider->getEntityTypes();

        $this->assertCount(2, $entityTypes);
        $this->assertSame('node', $entityTypes[0]->id());
        $this->assertSame(Node::class, $entityTypes[0]->getClass());
        $this->assertSame('node_type', $entityTypes[1]->id());
        $this->assertSame(NodeType::class, $entityTypes[1]->getClass());
    }

    #[Test]
    public function node_entity_type_has_field_definitions(): void
    {
        $provider = new NodeServiceProvider();
        $provider->register();

        $fields = $provider->getEntityTypes()[0]->getFieldDefinitions();

        $this->assertArrayHasKey('title', $fields);
        $this->assertSame('string', $fields['title']['type']);
        $this->assertTrue($fields['title']['required']);

        $this->assertArrayHasKey('type', $fields);
        $this->assertSame('string', $fields['type']['type']);
        $this->assertTrue($fields['type']['required']);
        $this->assertTrue($fields['type']['readOnly']);

        $this->assertArrayHasKey('slug', $fields);
        $this->assertSame('string', $fields['slug']['type']);
        $this->assertTrue($fields['slug']['required']);

        $this->assertArrayHasKey('status', $fields);
        $this->assertArrayHasKey('promote', $fields);
        $this->assertArrayHasKey('sticky', $fields);

        $this->assertArrayHasKey('uid', $fields);
        $this->assertSame('user', $fields['uid']['target_entity_type_id']);

        $this->assertArrayHasKey('created', $fields);
        $this->assertArrayHasKey('changed', $fields);

        $this->assertArrayHasKey('workflow_state', $fields);
        $this->assertSame('string', $fields['workflow_state']['type']);
    }

    #[Test]
    public function node_entity_type_is_revisionable_with_revision_id_key(): void
    {
        $provider = new NodeServiceProvider();
        $provider->register();

        $nodeEntityType = $provider->getEntityTypes()[0];

        $this->assertTrue($nodeEntityType->isRevisionable());
        $this->assertTrue($nodeEntityType->getRevisionDefault());
        $this->assertSame('revision_id', $nodeEntityType->getKeys()['revision']);
    }

    #[Test]
    public function node_type_has_no_field_definitions(): void
    {
        $provider = new NodeServiceProvider();
        $provider->register();

        $fields = $provider->getEntityTypes()[1]->getFieldDefinitions();

        $this->assertSame([], $fields);
    }

    #[Test]
    public function boot_wires_the_revision_default_listener_to_pre_save_event(): void
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $entityTypeManager = new EntityTypeManager($dispatcher);

        // Mirrors RelationshipServiceProviderTest::boot_wires_delete_guard_to_pre_delete_event
        // (#1852 kernel-services-bus gotcha): the dispatcher is served ONLY
        // under the Symfony-contracts FQCN.
        $provider = new NodeServiceProvider();
        $provider->setKernelServices($this->kernelServices([
            \Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class => $dispatcher,
            EntityTypeManager::class => $entityTypeManager,
        ]));
        $provider->register();
        $provider->boot();

        $listeners = $dispatcher->getListeners(EntityEvents::PRE_SAVE->value);
        $this->assertNotEmpty($listeners, 'NodeRevisionDefaultListener must subscribe to pre-save');
        $this->assertInstanceOf(NodeRevisionDefaultListener::class, $listeners[0]);
    }

    #[Test]
    public function boot_without_dispatcher_is_a_no_op(): void
    {
        $provider = new NodeServiceProvider();
        $provider->setKernelServices($this->kernelServices([]));
        $provider->register();

        $provider->boot();
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function boot_without_entity_type_manager_is_a_no_op(): void
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();

        $provider = new NodeServiceProvider();
        $provider->setKernelServices($this->kernelServices([
            \Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class => $dispatcher,
        ]));
        $provider->register();
        $provider->boot();

        $this->assertSame(
            [],
            $dispatcher->getListeners(EntityEvents::PRE_SAVE->value),
            'Without a resolvable EntityTypeManager, no listener may be registered.',
        );
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
