<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Schema\ConfigSchemaRegistry;
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
        $provider = new WorkflowServiceProvider();
        $provider->setKernelServices(new class ($registry) implements KernelServicesInterface {
            public function __construct(private readonly ConfigSchemaRegistry $registry) {}

            public function get(string $abstract): ?object
            {
                return $abstract === ConfigSchemaRegistry::class ? $this->registry : null;
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
    }

    #[Test]
    public function boot_without_configuration_authority_does_not_manufacture_a_registry(): void
    {
        $provider = new WorkflowServiceProvider();
        $provider->register();

        $provider->boot();

        self::addToAssertionCount(1);
    }
}
