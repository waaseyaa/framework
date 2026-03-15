<?php

declare(strict_types=1);

namespace Waaseyaa\AdminBridge\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AdminBridge\AdminAccount;
use Waaseyaa\AdminBridge\AdminAuthConfig;
use Waaseyaa\AdminBridge\AdminBootstrapController;
use Waaseyaa\AdminBridge\AdminBootstrapPayload;
use Waaseyaa\AdminBridge\AdminTenant;
use Waaseyaa\AdminBridge\AdminTransportConfig;
use Waaseyaa\AdminBridge\CatalogBuilder;
use Waaseyaa\Entity\EntityTypeManagerInterface;

#[CoversClass(AdminBootstrapController::class)]
#[CoversClass(AdminBootstrapPayload::class)]
#[CoversClass(AdminAccount::class)]
final class AdminBootstrapControllerTest extends TestCase
{
    #[Test]
    public function invoke_returns_correct_payload_structure(): void
    {
        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn([]);

        $controller = new AdminBootstrapController(
            catalogBuilder: new CatalogBuilder($manager),
            authConfig: new AdminAuthConfig(strategy: 'redirect', loginUrl: '/login'),
            transportConfig: new AdminTransportConfig(strategy: 'jsonapi', apiPath: '/api'),
            tenant: new AdminTenant(id: 'default', name: 'Test Site'),
        );

        $account = new class implements AccountInterface {
            public function id(): int|string { return 42; }
            public function hasPermission(string $permission): bool { return false; }
            public function getRoles(): array { return ['admin', 'editor']; }
            public function isAuthenticated(): bool { return true; }
        };

        $result = $controller($account);

        $this->assertArrayHasKey('version', $result);
        $this->assertArrayHasKey('auth', $result);
        $this->assertArrayHasKey('account', $result);
        $this->assertArrayHasKey('tenant', $result);
        $this->assertArrayHasKey('transport', $result);
        $this->assertArrayHasKey('entities', $result);

        $this->assertSame('1.0', $result['version']);
    }

    #[Test]
    public function invoke_maps_account_correctly(): void
    {
        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn([]);

        $controller = new AdminBootstrapController(
            catalogBuilder: new CatalogBuilder($manager),
            authConfig: new AdminAuthConfig(),
            transportConfig: new AdminTransportConfig(),
            tenant: new AdminTenant(id: 'default', name: 'Test'),
        );

        $account = new class implements AccountInterface {
            public function id(): int|string { return 7; }
            public function hasPermission(string $permission): bool { return false; }
            public function getRoles(): array { return ['editor']; }
            public function isAuthenticated(): bool { return true; }
        };

        $result = $controller($account);

        $this->assertSame('7', $result['account']['id']);
        $this->assertSame('editor', $result['account']['name']);
        $this->assertSame(['editor'], $result['account']['roles']);
    }

    #[Test]
    public function invoke_includes_auth_and_transport_config(): void
    {
        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn([]);

        $controller = new AdminBootstrapController(
            catalogBuilder: new CatalogBuilder($manager),
            authConfig: new AdminAuthConfig(strategy: 'session', loginEndpoint: '/api/login'),
            transportConfig: new AdminTransportConfig(strategy: 'jsonapi', apiPath: '/v1'),
            tenant: new AdminTenant(id: 'site1', name: 'Site One'),
        );

        $account = new class implements AccountInterface {
            public function id(): int|string { return 1; }
            public function hasPermission(string $permission): bool { return false; }
            public function getRoles(): array { return ['authenticated']; }
            public function isAuthenticated(): bool { return true; }
        };

        $result = $controller($account);

        $this->assertSame('session', $result['auth']['strategy']);
        $this->assertSame('/api/login', $result['auth']['loginEndpoint']);
        $this->assertSame('jsonapi', $result['transport']['strategy']);
        $this->assertSame('/v1', $result['transport']['apiPath']);
        $this->assertSame('site1', $result['tenant']['id']);
        $this->assertSame('Site One', $result['tenant']['name']);
    }
}
