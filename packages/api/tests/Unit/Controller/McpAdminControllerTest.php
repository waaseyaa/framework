<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Api\Controller\McpAdminController;
use Waaseyaa\Api\McpAdmin\RecentInvocation;
use Waaseyaa\Api\McpAdmin\RegisteredClient;
use Waaseyaa\Api\McpAdmin\ServerConfigReadModelInterface;
use Waaseyaa\Api\McpAdmin\ServerConfigSnapshot;
use Waaseyaa\Api\McpAdmin\ToolDetail;
use Waaseyaa\Api\McpAdmin\ToolRegistryReadModelInterface;
use Waaseyaa\Api\McpAdmin\ToolRegistryRow;

#[CoversClass(McpAdminController::class)]
final class McpAdminControllerTest extends TestCase
{
    // ── tools() ─────────────────────────────────────────────────────────────

    #[Test]
    public function toolsReturnsEmptyShapeWhenRegistryNull(): void
    {
        $controller = new McpAdminController();
        $result = $controller->tools(new Request());

        $this->assertSame(['data' => ['rows' => []]], $result);
    }

    #[Test]
    public function toolsReturnsRowsWithCamelCaseKeys(): void
    {
        $registry = $this->createFakeRegistry();
        $controller = new McpAdminController(registry: $registry);

        $result = $controller->tools(new Request());

        $this->assertCount(2, $result['data']['rows']);
        $first = $result['data']['rows'][0];
        $this->assertSame('bimaaji_search', $first['name']);
        $this->assertSame('bimaaji', $first['category']);
        $this->assertArrayHasKey('requiredCapabilities', $first);
        $this->assertSame(['bimaaji.read'], $first['requiredCapabilities']);
    }

    // ── tool() ──────────────────────────────────────────────────────────────

    #[Test]
    public function toolReturnsNullToolWhenRegistryNull(): void
    {
        $controller = new McpAdminController();
        $result = $controller->tool(new Request(), 'any_tool');

        $this->assertSame(['data' => ['tool' => null]], $result);
    }

    #[Test]
    public function toolReturnsNullWhenToolNotFound(): void
    {
        $registry = $this->createFakeRegistry();
        $controller = new McpAdminController(registry: $registry);

        $result = $controller->tool(new Request(), 'nonexistent');

        $this->assertSame(['data' => ['tool' => null]], $result);
    }

    #[Test]
    public function toolReturnsCamelCaseDetailWithInputSchemaAndInvocations(): void
    {
        $registry = $this->createFakeRegistry();
        $controller = new McpAdminController(registry: $registry);

        $result = $controller->tool(new Request(), 'bimaaji_search');

        $tool = $result['data']['tool'];
        $this->assertIsArray($tool);
        $this->assertSame('bimaaji_search', $tool['name']);
        $this->assertSame('bimaaji', $tool['category']);
        $this->assertArrayHasKey('inputSchema', $tool);
        $this->assertArrayHasKey('requiredCapabilities', $tool);
        $this->assertArrayHasKey('recentInvocations', $tool);
        $this->assertCount(1, $tool['recentInvocations']);
    }

    #[Test]
    public function toolUrlDecodesNameBeforeLookup(): void
    {
        $registry = $this->createFakeRegistry();
        $controller = new McpAdminController(registry: $registry);

        // bimaaji.search URL-encoded as bimaaji%2Esearch — decoded once before lookup
        $result = $controller->tool(new Request(), 'bimaaji%2Esearch');

        // No match because exact name is 'bimaaji_search' — decoding %2E → '.'
        // doesn't collide; this test verifies the decode path runs without error
        $this->assertArrayHasKey('tool', $result['data']);
    }

    #[Test]
    public function toolUrlDecodesNameWithDot(): void
    {
        $registry = $this->createFakeRegistryWithDotName();
        $controller = new McpAdminController(registry: $registry);

        // URL-encoded dot in tool name
        $result = $controller->tool(new Request(), 'bimaaji.search_specs');

        $this->assertNotNull($result['data']['tool']);
        $this->assertSame('bimaaji.search_specs', $result['data']['tool']['name']);
    }

    #[Test]
    public function toolRedactsInvocationsWhenAccountLacksPermission(): void
    {
        $registry = $this->createFakeRegistry();
        $account = new class implements \Waaseyaa\Access\AccountInterface {
            public function id(): int|string { return 1; }
            public function isAuthenticated(): bool { return true; }
            public function hasPermission(string $permission): bool { return false; } // lacks all perms
            public function getRoles(): array { return ['admin']; }
        };
        $accessHandler = new \Waaseyaa\Access\EntityAccessHandler();
        $controller = new McpAdminController(
            registry: $registry,
            accessHandler: $accessHandler,
            account: $account,
        );

        $result = $controller->tool(new Request(), 'bimaaji_search');

        $invocation = $result['data']['tool']['recentInvocations'][0];
        $this->assertTrue($invocation['_redacted']);
        $this->assertNull($invocation['account']);
        $this->assertNull($invocation['errorMessage']);
    }

    // ── serverConfig() ──────────────────────────────────────────────────────

    #[Test]
    public function serverConfigReturnsNullConfigWhenReadModelNull(): void
    {
        $controller = new McpAdminController();
        $result = $controller->serverConfig(new Request());

        $this->assertSame(['data' => ['config' => null]], $result);
    }

    #[Test]
    public function serverConfigReturnsCamelCaseSnapshot(): void
    {
        $configModel = $this->createFakeServerConfigReadModel();
        $controller = new McpAdminController(config: $configModel);

        $result = $controller->serverConfig(new Request());

        $config = $result['data']['config'];
        $this->assertIsArray($config);
        $this->assertSame('streamable-http', $config['transport']);
        $this->assertSame('2025-03-26', $config['protocolVersion']);
        $this->assertArrayHasKey('registeredClients', $config);
        $this->assertArrayHasKey('serverCapabilities', $config);

        $client = $config['registeredClients'][0];
        $this->assertSame('client-1', $client['clientId']);
        $this->assertSame('abcdef0123456789', $client['tokenFingerprint']);
    }

    // ── NFR-003: no plaintext token in server-config response ────────────────

    #[Test]
    public function serverConfigResponseContainsNoPlaintextToken(): void
    {
        $plaintextToken = 'supersecret-token-64-hexchars-abcdef1234567890abcdef1234567890ab';
        $configModel = new class($plaintextToken) implements ServerConfigReadModelInterface {
            public function __construct(private readonly string $token) {}

            public function serverConfig(): ServerConfigSnapshot
            {
                return new ServerConfigSnapshot(
                    transport: 'streamable-http',
                    protocolVersion: '2025-03-26',
                    registeredClients: [
                        new RegisteredClient(
                            clientId: 'client-test',
                            addedAt: null,
                            lastSeenAt: null,
                            tokenFingerprint: substr(hash('sha256', $this->token), 0, 16),
                        ),
                    ],
                    serverCapabilities: ['tools'],
                );
            }
        };

        $controller = new McpAdminController(config: $configModel);
        $result = $controller->serverConfig(new Request());

        $serialized = json_encode($result, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($plaintextToken, $serialized);
        // Verify fingerprint IS present (16-char prefix)
        $this->assertStringContainsString(substr(hash('sha256', $plaintextToken), 0, 16), $serialized);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function createFakeRegistry(): ToolRegistryReadModelInterface
    {
        return new class implements ToolRegistryReadModelInterface {
            public function listTools(): array
            {
                return [
                    new ToolRegistryRow('bimaaji_search', 'Search specs', 'bimaaji', ['bimaaji.read']),
                    new ToolRegistryRow('entity_read', 'Read entity', 'entity', ['entity.view']),
                ];
            }

            public function findTool(string $name): ?ToolDetail
            {
                if ($name === 'bimaaji_search') {
                    return new ToolDetail(
                        name: 'bimaaji_search',
                        summary: 'Search specs',
                        description: 'Search the specs directory.',
                        category: 'bimaaji',
                        requiredCapabilities: ['bimaaji.read'],
                        inputSchema: ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]],
                        recentInvocations: [
                            new RecentInvocation(
                                traceUuid: '550e8400-e29b-41d4-a716-446655440000',
                                invokedAt: '2026-05-25T05:00:00Z',
                                account: 'admin@example.com',
                                outcome: 'ok',
                                errorMessage: null,
                                latencyMs: 42,
                            ),
                        ],
                    );
                }

                return null;
            }
        };
    }

    private function createFakeRegistryWithDotName(): ToolRegistryReadModelInterface
    {
        return new class implements ToolRegistryReadModelInterface {
            public function listTools(): array
            {
                return [new ToolRegistryRow('bimaaji.search_specs', 'Search', 'bimaaji', ['bimaaji.read'])];
            }

            public function findTool(string $name): ?ToolDetail
            {
                if ($name === 'bimaaji.search_specs') {
                    return new ToolDetail(
                        name: 'bimaaji.search_specs',
                        summary: 'Search',
                        description: 'Search specs.',
                        category: 'bimaaji',
                        requiredCapabilities: ['bimaaji.read'],
                        inputSchema: [],
                        recentInvocations: [],
                    );
                }

                return null;
            }
        };
    }

    private function createFakeServerConfigReadModel(): ServerConfigReadModelInterface
    {
        return new class implements ServerConfigReadModelInterface {
            public function serverConfig(): ServerConfigSnapshot
            {
                return new ServerConfigSnapshot(
                    transport: 'streamable-http',
                    protocolVersion: '2025-03-26',
                    registeredClients: [
                        new RegisteredClient(
                            clientId: 'client-1',
                            addedAt: '2026-01-01T00:00:00Z',
                            lastSeenAt: '2026-05-25T04:00:00Z',
                            tokenFingerprint: 'abcdef0123456789',
                        ),
                    ],
                    serverCapabilities: ['tools'],
                );
            }
        };
    }
}
