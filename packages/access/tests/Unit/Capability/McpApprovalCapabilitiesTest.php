<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Tests\Unit\Capability;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\Capability\McpApprovalCapabilities;
use Waaseyaa\Access\PermissionHandler;

#[CoversClass(McpApprovalCapabilities::class)]
final class McpApprovalCapabilitiesTest extends TestCase
{
    #[Test]
    public function allExposesExactlyTheTwoApprovalCapabilities(): void
    {
        self::assertSame(
            ['mcp.approval.view', 'mcp.approval.decide'],
            McpApprovalCapabilities::all(),
        );
    }

    #[Test]
    public function constantsMatchTheCanonicalIdentifiers(): void
    {
        self::assertSame('mcp.approval.view', McpApprovalCapabilities::PERMISSION_VIEW);
        self::assertSame('mcp.approval.decide', McpApprovalCapabilities::PERMISSION_DECIDE);
    }

    #[Test]
    public function seedHasDescriptorForEveryAllEntry(): void
    {
        $seed = McpApprovalCapabilities::seed();

        foreach (McpApprovalCapabilities::all() as $id) {
            self::assertArrayHasKey($id, $seed, "Seed missing descriptor for $id");
            self::assertArrayHasKey('title', $seed[$id]);
            self::assertArrayHasKey('description', $seed[$id]);
            self::assertNotSame('', $seed[$id]['title']);
            self::assertNotSame('', $seed[$id]['description']);
        }
    }

    #[Test]
    public function decideDescriptorDocumentsSeparationOfDuties(): void
    {
        $seed = McpApprovalCapabilities::seed();

        // The decide capability is the separation-of-duties gate: holders
        // decide OTHER principals' destructive MCP calls; self-approval is
        // refused by default. The seed must carry that warning so an app
        // granting the permission understands what it is handing out.
        self::assertStringContainsString(
            'self-approval',
            strtolower($seed['mcp.approval.decide']['description']),
        );
    }

    #[Test]
    public function theSeedIsAppRegistered_theFrameworkBindsNoPermissionHandler(): void
    {
        // Seed-only contract (mirrors AgentCapabilities): enforcement uses the
        // permission STRING on the route; the registry is app-side discovery.
        // A permission handler starts empty — nothing in framework code has
        // pre-registered the approval capabilities — and register() is the
        // one documented installation point.
        $handler = new PermissionHandler();

        foreach (McpApprovalCapabilities::all() as $id) {
            self::assertFalse(
                $handler->hasPermission($id),
                "$id must not be pre-registered anywhere — apps opt in via register().",
            );
        }
    }

    #[Test]
    public function registerInstallsEverySeedEntryIntoPermissionHandler(): void
    {
        $handler = new PermissionHandler();

        McpApprovalCapabilities::register($handler);

        foreach (McpApprovalCapabilities::all() as $id) {
            self::assertTrue(
                $handler->hasPermission($id),
                "register() must install $id into the PermissionHandler",
            );
        }
    }
}
