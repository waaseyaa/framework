<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\AgentRuntime;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Agent\Mcp\McpClientToolSource;
use Waaseyaa\AI\Agent\Mcp\McpCredentialOperation;
use Waaseyaa\AI\Agent\Mcp\McpIntegrationHealth;
use Waaseyaa\AI\Agent\Mcp\McpReadinessException;
use Waaseyaa\AI\Agent\Mcp\StreamableHttpMcpClient;
use Waaseyaa\AI\Tools\Catalogue\AttributeToolRegistry;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Config\Schema\Ai\McpServersConfig;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Log\Processor\RedactorProcessor;
use Waaseyaa\Foundation\Security\SecretClass;
use Waaseyaa\Foundation\Security\SecretProviderInterface;
use Waaseyaa\Foundation\Security\SecretReference;
use Waaseyaa\Foundation\Security\SecretResolverRegistry;
use Waaseyaa\Foundation\Security\SensitiveValue;
use Waaseyaa\HttpClient\HttpClientInterface;
use Waaseyaa\HttpClient\HttpRequestException;
use Waaseyaa\HttpClient\HttpResponse;
use Waaseyaa\Tests\Integration\PhaseN\AgentRuntime\Fixture\InMemoryConfigStorage;
use Waaseyaa\Tests\Integration\PhaseN\AgentRuntime\Fixture\StubMcpServerHttpClient;

#[CoversNothing]
final class McpCredentialCustodyTest extends TestCase
{
    #[Test]
    public function explicit_none_sends_no_authorization_header_by_construction(): void
    {
        $http = $this->recordingHttp();
        $registry = $this->toolRegistry();
        $source = $this->source(
            $http,
            $registry,
            $this->storage('optional', 'none'),
        );

        $source->bootstrap();

        self::assertTrue($registry->has('stub.echo'));
        self::assertNotEmpty($http->requests);
        foreach ($http->requests as $request) {
            self::assertArrayNotHasKey('Authorization', $request['headers']);
        }
    }

    #[Test]
    public function optional_missing_credential_degrades_without_a_tool_or_network_request(): void
    {
        $http = $this->recordingHttp();
        $tools = $this->toolRegistry();
        $health = new McpIntegrationHealth();
        $secrets = $this->secretRegistry(provider: null);
        $source = $this->source(
            $http,
            $tools,
            $this->storage('optional', 'secret-reference'),
            $secrets,
            $health,
        );

        $source->bootstrap();

        self::assertFalse($tools->has('stub.echo'));
        self::assertSame([], $http->requests);
        self::assertSame([
            'state' => 'degraded',
            'reason' => 'credential_unavailable',
        ], $health->status('stub'));
    }

    #[Test]
    public function required_missing_credential_blocks_readiness_before_network(): void
    {
        $http = $this->recordingHttp();
        $health = new McpIntegrationHealth();
        $source = $this->source(
            $http,
            $this->toolRegistry(),
            $this->storage('required', 'secret-reference'),
            $this->secretRegistry(provider: null),
            $health,
        );

        try {
            $source->bootstrap();
            self::fail('A required MCP credential failure must block readiness.');
        } catch (McpReadinessException $exception) {
            self::assertStringNotContainsString('tenant/stub/mcp-authorization', $exception->getMessage());
            self::assertStringNotContainsString('CFG04-MCP', $exception->getMessage());
        }

        self::assertSame([], $http->requests);
        self::assertSame([
            'state' => 'blocked',
            'reason' => 'credential_unavailable',
        ], $health->status('stub'));
    }

    #[Test]
    public function startup_and_each_call_pin_one_fresh_rotated_version(): void
    {
        $provider = new RotatingMcpCredentialProvider();
        $secrets = $this->secretRegistry($provider);
        $http = $this->recordingHttp();
        $tools = $this->toolRegistry();
        $source = $this->source(
            $http,
            $tools,
            $this->storage('required', 'secret-reference'),
            $secrets,
            new McpIntegrationHealth(),
        );

        $source->bootstrap();

        self::assertSame(1, $provider->resolveCount, 'startup health must pin one version across initialize/list');
        self::assertSame(['Bearer CFG04-MCP-ROTATION-v1'], $http->authorizationValues());

        $result = $tools->get('stub.echo')->impl->execute(
            ['message' => 'hello'],
            $this->account('tool.mcp.stub.echo'),
        );

        self::assertFalse($result->isError);
        self::assertSame(2, $provider->resolveCount, 'the next call must resolve a fresh version exactly once');
        self::assertSame([
            'Bearer CFG04-MCP-ROTATION-v1',
            'Bearer CFG04-MCP-ROTATION-v2',
        ], $http->authorizationValues());
    }

    #[Test]
    public function authenticated_transport_outage_is_not_misreported_as_a_credential_failure(): void
    {
        $http = new RecordingMcpHttpClient(new FailingMcpHttpClient());
        $health = new McpIntegrationHealth();
        $tools = $this->toolRegistry();
        $source = $this->source(
            $http,
            $tools,
            $this->storage('optional', 'secret-reference'),
            $this->secretRegistry(new RotatingMcpCredentialProvider()),
            $health,
        );

        $source->bootstrap();

        self::assertFalse($tools->has('stub.echo'));
        self::assertCount(1, $http->requests);
        self::assertSame([
            'state' => 'degraded',
            'reason' => 'server_unavailable',
        ], $health->status('stub'));
    }

    #[Test]
    public function authenticated_json_rpc_startup_error_is_not_misreported_as_a_credential_failure(): void
    {
        $http = new RecordingMcpHttpClient(new JsonRpcErrorMcpHttpClient('initialize'));
        $health = new McpIntegrationHealth();
        $tools = $this->toolRegistry();
        $source = $this->source(
            $http,
            $tools,
            $this->storage('optional', 'secret-reference'),
            $this->secretRegistry(new RotatingMcpCredentialProvider()),
            $health,
        );

        $source->bootstrap();

        self::assertFalse($tools->has('stub.echo'));
        self::assertCount(1, $http->requests);
        self::assertSame([
            'state' => 'degraded',
            'reason' => 'server_unavailable',
        ], $health->status('stub'));
    }

    #[Test]
    public function authenticated_json_rpc_tool_error_crosses_custody_as_remote_error(): void
    {
        $http = new RecordingMcpHttpClient(new JsonRpcErrorMcpHttpClient('tools/call'));
        $health = new McpIntegrationHealth();
        $tools = $this->toolRegistry();
        $source = $this->source(
            $http,
            $tools,
            $this->storage('required', 'secret-reference'),
            $this->secretRegistry(new RotatingMcpCredentialProvider()),
            $health,
        );

        $source->bootstrap();
        $result = $tools->get('stub.echo')->impl->execute(
            ['message' => 'hello'],
            $this->account('tool.mcp.stub.echo'),
        );

        self::assertTrue($result->isError);
        self::assertSame('remote_error', $result->summary);
        self::assertSame([
            'state' => 'healthy',
            'reason' => null,
        ], $health->status('stub'));
    }

    private function source(
        RecordingMcpHttpClient $http,
        ToolRegistryInterface $tools,
        InMemoryConfigStorage $storage,
        ?SecretResolverRegistry $secrets = null,
        ?McpIntegrationHealth $health = null,
    ): McpClientToolSource {
        return new McpClientToolSource(
            new StreamableHttpMcpClient($http),
            $tools,
            $storage,
            secretResolverRegistry: $secrets,
            health: $health,
        );
    }

    private function storage(string $availability, string $authMode): InMemoryConfigStorage
    {
        $row = [
            'alias' => 'stub',
            'url' => 'https://stub.invalid/mcp',
            'auth_mode' => $authMode,
            'availability' => $availability,
            'enabled' => true,
            'capability_prefix' => 'tool.mcp.stub',
        ];
        if ($authMode === 'secret-reference') {
            $row['credential_reference'] = [
                'provider' => 'synthetic-vault',
                'identifier' => 'tenant/stub/mcp-authorization',
                'secret_class' => 'integration-credential',
                'purpose' => McpServersConfig::AUTHORIZATION_PURPOSE,
            ];
        }

        $storage = new InMemoryConfigStorage();
        $storage->write(McpServersConfig::CONFIG_NAME, [McpServersConfig::ITEMS_KEY => [$row]]);

        return $storage;
    }

    private function secretRegistry(?SecretProviderInterface $provider): SecretResolverRegistry
    {
        $registry = new SecretResolverRegistry(new RedactorProcessor(), 'testing');
        if ($provider !== null) {
            $registry->registerProvider($provider);
        }
        $registry->allow(
            'synthetic-vault',
            'waaseyaa/ai-agent',
            SecretClass::IntegrationCredential,
            McpServersConfig::AUTHORIZATION_PURPOSE,
            ['testing'],
        );
        $registry->registerConsumer('waaseyaa/ai-agent', McpCredentialOperation::class);
        $registry->freeze();

        return $registry;
    }

    private function recordingHttp(): RecordingMcpHttpClient
    {
        $server = new StubMcpServerHttpClient();
        $server->registerTool('echo', 'Echo input', ['type' => 'object'], static fn(array $args): array => [
            'isError' => false,
            'content' => [['type' => 'text', 'text' => (string) ($args['message'] ?? '')]],
        ]);

        return new RecordingMcpHttpClient($server);
    }

    private function toolRegistry(): AttributeToolRegistry
    {
        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException('No fixture service: ' . $id);
            }

            public function has(string $id): bool
            {
                return false;
            }
        };

        return new AttributeToolRegistry(new PackageManifest(), $container);
    }

    private function account(string $permission): AccountInterface
    {
        return new class ($permission) implements AccountInterface {
            public function __construct(private readonly string $permission) {}

            public function id(): int|string
            {
                return 1;
            }

            public function isAuthenticated(): bool
            {
                return true;
            }

            public function hasPermission(string $permission): bool
            {
                return $permission === $this->permission;
            }

            public function getRoles(): array
            {
                return [];
            }
        };
    }
}

final class RecordingMcpHttpClient implements HttpClientInterface
{
    /** @var list<array{method: string, url: string, headers: array<string, string>}> */
    public array $requests = [];

    public function __construct(private readonly HttpClientInterface $inner) {}

    public function request(string $method, string $url, array $headers = [], array|string|null $body = null): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers];

        return $this->inner->request($method, $url, $headers, $body);
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        return $this->request('GET', $url, $headers);
    }

    public function post(string $url, array $headers = [], array|string|null $body = null): HttpResponse
    {
        return $this->request('POST', $url, $headers, $body);
    }

    /** @return list<string> */
    public function authorizationValues(): array
    {
        $values = [];
        foreach ($this->requests as $request) {
            if (isset($request['headers']['Authorization'])) {
                $values[$request['headers']['Authorization']] = true;
            }
        }

        return array_keys($values);
    }
}

final class RotatingMcpCredentialProvider implements SecretProviderInterface
{
    public int $resolveCount = 0;

    public function id(): string
    {
        return 'synthetic-vault';
    }

    public function resolve(SecretReference $reference): SensitiveValue
    {
        ++$this->resolveCount;

        return SensitiveValue::fromBytes(
            'Bearer CFG04-MCP-ROTATION-v' . $this->resolveCount,
            SecretClass::IntegrationCredential,
            'synthetic-v' . $this->resolveCount,
        );
    }
}

final class FailingMcpHttpClient implements HttpClientInterface
{
    public function request(string $method, string $url, array $headers = [], array|string|null $body = null): HttpResponse
    {
        throw new HttpRequestException('synthetic MCP outage', $url, $method);
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        return $this->request('GET', $url, $headers);
    }

    public function post(string $url, array $headers = [], array|string|null $body = null): HttpResponse
    {
        return $this->request('POST', $url, $headers, $body);
    }
}

final class JsonRpcErrorMcpHttpClient implements HttpClientInterface
{
    private readonly StubMcpServerHttpClient $inner;

    public function __construct(private readonly string $methodToFail)
    {
        $this->inner = new StubMcpServerHttpClient();
        $this->inner->registerTool('echo', 'Echo input', ['type' => 'object'], static fn(array $args): array => [
            'isError' => false,
            'content' => [['type' => 'text', 'text' => (string) ($args['message'] ?? '')]],
        ]);
    }

    public function request(string $method, string $url, array $headers = [], array|string|null $body = null): HttpResponse
    {
        $payload = is_string($body) ? json_decode($body, true, 512, JSON_THROW_ON_ERROR) : $body;
        if (is_array($payload) && ($payload['method'] ?? null) === $this->methodToFail) {
            return new HttpResponse(200, json_encode([
                'jsonrpc' => '2.0',
                'id' => $payload['id'] ?? null,
                'error' => [
                    'code' => -32601,
                    'message' => 'CFG04-REMOTE-CANARY',
                ],
            ], JSON_THROW_ON_ERROR));
        }

        return $this->inner->request($method, $url, $headers, $body);
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        return $this->request('GET', $url, $headers);
    }

    public function post(string $url, array $headers = [], array|string|null $body = null): HttpResponse
    {
        return $this->request('POST', $url, $headers, $body);
    }
}
