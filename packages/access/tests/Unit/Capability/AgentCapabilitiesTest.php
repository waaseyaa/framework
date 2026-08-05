<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Tests\Unit\Capability;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\Capability\AgentCapabilities;
use Waaseyaa\Access\PermissionHandler;

#[CoversClass(AgentCapabilities::class)]
final class AgentCapabilitiesTest extends TestCase
{
    #[Test]
    public function allExposesTwelveStaticCapabilityNames(): void
    {
        $all = AgentCapabilities::all();

        self::assertCount(12, $all);
        self::assertContains('agent.run', $all);
        self::assertContains('agent.run.approve', $all);
        self::assertContains('agent.run.bypass_ownership', $all);
        self::assertContains('tool.entity.read', $all);
        self::assertContains('tool.entity.list', $all);
        self::assertContains('tool.entity.create', $all);
        self::assertContains('tool.entity.update', $all);
        self::assertContains('tool.entity.delete', $all);
        self::assertContains('tool.entity.search', $all);
        self::assertContains('tool.content.search', $all);
        self::assertContains('tool.relationship.traverse', $all);
        self::assertContains('tool.vector.search', $all);
    }

    #[Test]
    public function seedHasDescriptorForEveryAllEntry(): void
    {
        $seed = AgentCapabilities::seed();

        foreach (AgentCapabilities::all() as $id) {
            self::assertArrayHasKey($id, $seed, "Seed missing descriptor for $id");
            self::assertArrayHasKey('title', $seed[$id]);
            self::assertArrayHasKey('description', $seed[$id]);
            self::assertNotSame('', $seed[$id]['title']);
        }
    }

    #[Test]
    public function approveDescriptorReferencesSameDefaultAsRun(): void
    {
        $seed = AgentCapabilities::seed();

        // R-002: agent.run.approve defaults to the same audience as agent.run.
        // The seed encodes this as documentation text on the descriptor;
        // apps that want to narrow the audience override it.
        self::assertStringContainsString(
            'agent.run',
            $seed['agent.run.approve']['description'],
            'agent.run.approve seed must document its agent.run-equivalent default',
        );
    }

    #[Test]
    public function registerInstallsEverySeedEntryIntoPermissionHandler(): void
    {
        $handler = new PermissionHandler();

        AgentCapabilities::register($handler);

        foreach (AgentCapabilities::all() as $id) {
            self::assertTrue(
                $handler->hasPermission($id),
                "Permission handler must know about $id after register()",
            );
        }
    }
}
