<?php

declare(strict_types=1);

namespace Waaseyaa\AdminBridge\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AdminBridge\AdminAccount;
use Waaseyaa\AdminBridge\AdminAuthConfig;
use Waaseyaa\AdminBridge\AdminBootstrapPayload;
use Waaseyaa\AdminBridge\AdminTenant;
use Waaseyaa\AdminBridge\AdminTransportConfig;
use Waaseyaa\AdminBridge\CatalogCapabilities;
use Waaseyaa\AdminBridge\CatalogEntry;

#[CoversClass(AdminBootstrapPayload::class)]
#[CoversClass(AdminAccount::class)]
#[CoversClass(AdminAuthConfig::class)]
#[CoversClass(AdminTenant::class)]
#[CoversClass(AdminTransportConfig::class)]
#[CoversClass(CatalogEntry::class)]
#[CoversClass(CatalogCapabilities::class)]
final class AdminBootstrapPayloadTest extends TestCase
{
    #[Test]
    public function payload_serializes_to_valid_contract(): void
    {
        $payload = new AdminBootstrapPayload(
            auth: new AdminAuthConfig(strategy: 'redirect', loginUrl: '/login'),
            account: new AdminAccount(id: '1', name: 'Admin', roles: ['admin']),
            tenant: new AdminTenant(id: 'default', name: 'Test'),
            transport: new AdminTransportConfig(strategy: 'jsonapi', apiPath: '/api'),
            entities: [],
        );

        $json = $payload->toArray();

        $this->assertSame('1.0', $json['version']);
        $this->assertSame('redirect', $json['auth']['strategy']);
        $this->assertSame('/login', $json['auth']['loginUrl']);
        $this->assertSame('1', $json['account']['id']);
        $this->assertSame('Admin', $json['account']['name']);
        $this->assertSame(['admin'], $json['account']['roles']);
        $this->assertSame('server', $json['tenant']['scopingStrategy']);
        $this->assertSame('jsonapi', $json['transport']['strategy']);
        $this->assertSame('/api', $json['transport']['apiPath']);
        $this->assertSame([], $json['entities']);
        $this->assertInstanceOf(\stdClass::class, $json['features']);
    }

    #[Test]
    public function payload_rejects_invalid_version(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported admin contract version: 2.0');

        new AdminBootstrapPayload(
            auth: new AdminAuthConfig(strategy: 'redirect'),
            account: new AdminAccount(id: '1', name: 'Test', roles: []),
            tenant: new AdminTenant(id: 'x', name: 'X'),
            transport: new AdminTransportConfig(strategy: 'jsonapi'),
            entities: [],
            version: '2.0',
        );
    }

    #[Test]
    public function payload_serializes_entities(): void
    {
        $payload = new AdminBootstrapPayload(
            auth: new AdminAuthConfig(strategy: 'embedded', loginEndpoint: '/api/auth/login'),
            account: new AdminAccount(id: '42', name: 'Editor', roles: ['editor']),
            tenant: new AdminTenant(id: 'site1', name: 'Site One'),
            transport: new AdminTransportConfig(),
            entities: [
                new CatalogEntry(id: 'node', label: 'Content'),
                new CatalogEntry(
                    id: 'user',
                    label: 'User',
                    capabilities: new CatalogCapabilities(
                        list: true, get: true, create: true,
                        update: true, delete: true, schema: true,
                    ),
                ),
            ],
        );

        $json = $payload->toArray();

        $this->assertCount(2, $json['entities']);
        $this->assertSame('node', $json['entities'][0]['id']);
        $this->assertSame('Content', $json['entities'][0]['label']);
        $this->assertFalse($json['entities'][0]['capabilities']['create']);
        $this->assertTrue($json['entities'][1]['capabilities']['create']);
    }

    #[Test]
    public function payload_serializes_features(): void
    {
        $payload = new AdminBootstrapPayload(
            auth: new AdminAuthConfig(),
            account: new AdminAccount(id: '1', name: 'Admin', roles: []),
            tenant: new AdminTenant(id: 'default', name: 'Default'),
            transport: new AdminTransportConfig(),
            entities: [],
            features: ['ai_assist' => true, 'workflows' => false],
        );

        $json = $payload->toArray();

        $this->assertSame(['ai_assist' => true, 'workflows' => false], $json['features']);
    }

    #[Test]
    public function auth_config_filters_null_values(): void
    {
        $config = new AdminAuthConfig(strategy: 'redirect', loginUrl: '/login');
        $array = $config->toArray();

        $this->assertSame('redirect', $array['strategy']);
        $this->assertSame('/login', $array['loginUrl']);
        $this->assertArrayNotHasKey('loginEndpoint', $array);
        $this->assertArrayNotHasKey('logoutEndpoint', $array);
        $this->assertArrayNotHasKey('sessionEndpoint', $array);
    }

    #[Test]
    public function tenant_defaults_to_server_scoping(): void
    {
        $tenant = new AdminTenant(id: 'default', name: 'Default');

        $this->assertSame('server', $tenant->scopingStrategy);
        $this->assertSame('server', $tenant->toArray()['scopingStrategy']);
    }

    #[Test]
    public function catalog_capabilities_defaults(): void
    {
        $caps = new CatalogCapabilities();

        $this->assertTrue($caps->list);
        $this->assertTrue($caps->get);
        $this->assertFalse($caps->create);
        $this->assertFalse($caps->update);
        $this->assertFalse($caps->delete);
        $this->assertTrue($caps->schema);
    }
}
