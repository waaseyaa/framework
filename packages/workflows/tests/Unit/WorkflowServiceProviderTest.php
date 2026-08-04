<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Publishing\ContentPublicationTransitionerInterface;
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
}
