<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Schema\ConfigSchemaRegistry;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Publishing\ContentPublicationTransitionerInterface;
use Waaseyaa\Workflows\Config\WorkflowAssignmentsConfig;
use Waaseyaa\Workflows\Workflow;
use Waaseyaa\Workflows\WorkflowServiceProvider;

/**
 * @covers \Waaseyaa\Workflows\WorkflowServiceProvider
 */
#[CoversClass(WorkflowServiceProvider::class)]
final class WorkflowServiceProviderTest extends TestCase
{
    #[Test]
    public function registers_workflow(): void
    {
        $provider = new WorkflowServiceProvider();
        $provider->register();

        $entityTypes = $provider->getEntityTypes();

        $this->assertCount(1, $entityTypes);
        $this->assertSame('workflow', $entityTypes[0]->id());
        $this->assertSame(Workflow::class, $entityTypes[0]->getClass());
        $this->assertArrayHasKey(ContentPublicationTransitionerInterface::class, $provider->getBindings());
    }

    #[Test]
    public function boot_registers_the_assignment_schema_on_the_shared_authority_registry(): void
    {
        $registry = new ConfigSchemaRegistry();
        $entityTypes = new EntityTypeManager(new SymfonyEventDispatcherAdapter());
        $entityTypes->registerEntityType(new EntityType(
            id: 'note',
            label: 'Note',
            class: \stdClass::class,
            keys: ['id' => 'id'],
            revisionable: false,
        ));
        $provider = new WorkflowServiceProvider();
        $provider->setKernelServices(new class ($registry, $entityTypes) implements KernelServicesInterface {
            public function __construct(
                private readonly ConfigSchemaRegistry $registry,
                private readonly EntityTypeManagerInterface $entityTypes,
            ) {}

            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    ConfigSchemaRegistry::class => $this->registry,
                    EntityTypeManagerInterface::class => $this->entityTypes,
                    default => null,
                };
            }
        });
        $provider->register();

        $provider->boot();

        $registration = $registry->get(
            WorkflowAssignmentsConfig::CONFIG_NAME,
            WorkflowAssignmentsConfig::SCHEMA_VERSION,
        );
        self::assertNotNull($registration);
        self::assertSame(WorkflowAssignmentsConfig::OWNER_PACKAGE, $registration->ownerPackage);
        $registry->freeze();
        $violations = $registry->semanticViolations(
            WorkflowAssignmentsConfig::CONFIG_NAME,
            WorkflowAssignmentsConfig::SCHEMA_VERSION,
            ['note.note' => 'editorial'],
        );
        self::assertCount(1, $violations);
        self::assertStringContainsString('not revisionable', $violations[0]->message);
    }

    #[Test]
    public function boot_without_configuration_authority_does_not_manufacture_a_registry(): void
    {
        $provider = new WorkflowServiceProvider();
        $provider->register();

        $provider->boot();

        self::addToAssertionCount(1);
    }

    #[Test]
    public function boot_refuses_to_register_the_assignment_schema_without_semantic_authority(): void
    {
        $registry = new ConfigSchemaRegistry();
        $provider = new WorkflowServiceProvider();
        $provider->setKernelServices(new class ($registry) implements KernelServicesInterface {
            public function __construct(private readonly ConfigSchemaRegistry $registry) {}

            public function get(string $abstract): ?object
            {
                return $abstract === ConfigSchemaRegistry::class ? $this->registry : null;
            }
        });
        $provider->register();

        try {
            $provider->boot();
            self::fail('Boot must refuse a configuration authority it cannot semantically guard.');
        } catch (\LogicException $refusal) {
            self::assertStringContainsString('semantic', $refusal->getMessage());
        }

        self::assertNull($registry->get(
            WorkflowAssignmentsConfig::CONFIG_NAME,
            WorkflowAssignmentsConfig::SCHEMA_VERSION,
        ), 'A structurally-only schema must never reach the trusted registry.');
    }
}
