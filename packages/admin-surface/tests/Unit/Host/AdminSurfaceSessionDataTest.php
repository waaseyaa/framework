<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit\Host;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AdminSurface\Host\AdminSurfaceSessionData;
use Waaseyaa\AdminSurface\Host\AdminSurfaceUiPayload;

#[CoversClass(AdminSurfaceSessionData::class)]
final class AdminSurfaceSessionDataTest extends TestCase
{
    #[Test]
    public function toArrayReturnsFullStructure(): void
    {
        $session = new AdminSurfaceSessionData(
            accountId: '42',
            accountName: 'Admin User',
            roles: ['admin', 'editor'],
            policies: ['administer content', 'edit any content'],
            email: 'admin@example.com',
            tenantId: 'org-1',
            tenantName: 'Test Org',
            features: ['ai_assist' => true],
        );

        $result = $session->toArray();

        self::assertSame('42', $result['account']['id']);
        self::assertSame('Admin User', $result['account']['name']);
        self::assertSame('admin@example.com', $result['account']['email']);
        self::assertSame(['admin', 'editor'], $result['account']['roles']);
        self::assertSame('org-1', $result['tenant']['id']);
        self::assertSame('Test Org', $result['tenant']['name']);
        self::assertSame(['administer content', 'edit any content'], $result['policies']);
        self::assertSame(['ai_assist' => true], $result['features']);
        self::assertArrayNotHasKey('ui', $result);
    }

    #[Test]
    public function toArrayIncludesUiWhenNonEmpty(): void
    {
        $ui = AdminSurfaceUiPayload::fromArrays(
            headerLinks: [['label' => 'Help', 'href' => '/help']],
            sidebarItems: [['id' => 'c', 'label' => 'Custom', 'href' => '/custom']],
        );
        $session = new AdminSurfaceSessionData(
            accountId: '1',
            accountName: 'Admin',
            roles: [],
            policies: [],
            ui: $ui,
        );

        $result = $session->toArray();

        self::assertArrayHasKey('ui', $result);
        self::assertSame(
            [
                'headerLinks' => [['label' => 'Help', 'href' => '/help']],
                'sidebarItems' => [['id' => 'c', 'label' => 'Custom', 'href' => '/custom']],
            ],
            $result['ui'],
        );
    }

    #[Test]
    public function toArrayOmitsUiWhenPayloadIsEmpty(): void
    {
        $session = new AdminSurfaceSessionData(
            accountId: '1',
            accountName: 'Admin',
            roles: [],
            policies: [],
            ui: AdminSurfaceUiPayload::fromArrays(),
        );

        self::assertArrayNotHasKey('ui', $session->toArray());
    }

    #[Test]
    public function toArrayUsesDefaultsForOptionalFields(): void
    {
        $session = new AdminSurfaceSessionData(
            accountId: '1',
            accountName: 'User',
            roles: [],
            policies: [],
        );

        $result = $session->toArray();

        self::assertNull($result['account']['email']);
        self::assertSame('default', $result['tenant']['id']);
        self::assertSame('Default', $result['tenant']['name']);
        // Empty features array becomes stdClass for clean JSON serialization
        self::assertInstanceOf(\stdClass::class, $result['features']);
        // Same convention for the capability projection: always present, `{}` when empty
        self::assertInstanceOf(\stdClass::class, $result['capabilities']);
    }

    #[Test]
    public function toArrayIncludesCapabilityProjection(): void
    {
        $session = new AdminSurfaceSessionData(
            accountId: '1',
            accountName: 'Admin',
            roles: [],
            policies: [],
            capabilities: ['mcp.approval.decide' => false, 'mcp.approval.view' => true],
        );

        $result = $session->toArray();

        self::assertSame(
            ['mcp.approval.decide' => false, 'mcp.approval.view' => true],
            $result['capabilities'],
        );
    }

    #[Test]
    public function emptyCapabilitiesSerializeAsJsonObject(): void
    {
        $session = new AdminSurfaceSessionData(
            accountId: '1',
            accountName: 'Admin',
            roles: [],
            policies: [],
        );

        $json = json_encode($session->toArray(), JSON_THROW_ON_ERROR);

        self::assertStringContainsString('"capabilities":{}', $json);
    }
}
