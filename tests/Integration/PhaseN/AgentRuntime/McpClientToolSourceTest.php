<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\AgentRuntime;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Agent\Mcp\McpClientToolSource;
use Waaseyaa\AI\Agent\Mcp\StreamableHttpMcpClient;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolInterface;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Catalogue\AttributeToolRegistry;
use Waaseyaa\Config\Schema\Ai\McpServersConfig;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Tests\Integration\PhaseN\AgentRuntime\Fixture\InMemoryConfigStorage;
use Waaseyaa\Tests\Integration\PhaseN\AgentRuntime\Fixture\StubMcpServerHttpClient;

/**
 * End-to-end test for {@see McpClientToolSource} against an in-process
 * stub MCP server that speaks proper JSON-RPC 2.0 over the
 * {@see \Waaseyaa\HttpClient\HttpClientInterface} transport.
 *
 * The stub server defines two tools (`echo`, `add`). After
 * `bootstrap()` we assert both appear in the registry under the
 * configured `stub` alias and inspect the synthesised
 * {@see AgentTool} VO; one test then executes the registered tool
 * through {@see \Waaseyaa\AI\Tools\AgentToolInterface::execute()} to
 * round-trip a JSON-RPC payload.
 */
#[CoversNothing]
final class McpClientToolSourceTest extends TestCase
{
    #[Test]
    public function bootstrapRegistersRemoteToolsUnderAlias(): void
    {
        $http = new StubMcpServerHttpClient();
        $http->registerTool('echo', 'Echo back input', [
            'type' => 'object',
            'properties' => ['msg' => ['type' => 'string']],
            'required' => ['msg'],
        ], static fn(array $args): array => [
            'isError' => false,
            'content' => [['type' => 'text', 'text' => (string) ($args['msg'] ?? '')]],
        ]);
        $http->registerTool('add', 'Add two numbers', [
            'type' => 'object',
            'properties' => [
                'a' => ['type' => 'number'],
                'b' => ['type' => 'number'],
            ],
            'required' => ['a', 'b'],
        ], static fn(array $args): array => [
            'isError' => false,
            'content' => [['type' => 'text', 'text' => (string) (((float) ($args['a'] ?? 0)) + ((float) ($args['b'] ?? 0)))]],
        ]);

        $configStorage = new InMemoryConfigStorage();
        $configStorage->write(McpServersConfig::CONFIG_NAME, [
            McpServersConfig::ITEMS_KEY => [
                [
                    'alias' => 'stub',
                    'url' => 'https://stub.invalid/mcp',
                    'auth_header_env_var' => '',
                    'enabled' => true,
                    'capability_prefix' => 'tool.mcp.stub',
                ],
            ],
        ]);

        $registry = $this->makeRegistry();
        $client = new StreamableHttpMcpClient($http);
        $source = new McpClientToolSource($client, $registry, $configStorage);

        $source->bootstrap();

        self::assertTrue($registry->has('stub.echo'), 'echo tool registered under alias');
        self::assertTrue($registry->has('stub.add'), 'add tool registered under alias');

        $echo = $registry->get('stub.echo');
        self::assertInstanceOf(AgentTool::class, $echo);
        self::assertSame('stub.echo', $echo->name);
        self::assertSame('tool.mcp.stub.echo', $echo->capability);
        self::assertSame('mcp.stub', $echo->category);
        self::assertTrue($echo->destructive, 'remote MCP tools default to destructive');
        self::assertFalse($echo->dryRunSupported);
        self::assertSame('Echo back input', $echo->impl->description());
        self::assertSame('object', $echo->inputSchema['type']);
    }

    #[Test]
    public function executorRoundTripsThroughStubServer(): void
    {
        $http = new StubMcpServerHttpClient();
        $http->registerTool('echo', 'Echo back input', [
            'type' => 'object',
            'properties' => ['msg' => ['type' => 'string']],
        ], static fn(array $args): array => [
            'isError' => false,
            'content' => [['type' => 'text', 'text' => 'echoed:' . (string) ($args['msg'] ?? '')]],
        ]);

        $configStorage = new InMemoryConfigStorage();
        $configStorage->write(McpServersConfig::CONFIG_NAME, [
            McpServersConfig::ITEMS_KEY => [
                [
                    'alias' => 'stub',
                    'url' => 'https://stub.invalid/mcp',
                    'auth_header_env_var' => '',
                    'enabled' => true,
                    'capability_prefix' => 'tool.mcp.stub',
                ],
            ],
        ]);

        $registry = $this->makeRegistry();
        $source = new McpClientToolSource(new StreamableHttpMcpClient($http), $registry, $configStorage);
        $source->bootstrap();

        $tool = $registry->get('stub.echo');
        $account = $this->makeAccount('tool.mcp.stub.echo');
        $result = $tool->impl->execute(['msg' => 'hello'], $account);

        self::assertFalse($result->isError);
        self::assertSame('echoed:hello', $result->content[0]['text']);
    }

    #[Test]
    public function unavailableServerDegradesGracefully(): void
    {
        $http = new StubMcpServerHttpClient(serverAvailable: false);

        $configStorage = new InMemoryConfigStorage();
        $configStorage->write(McpServersConfig::CONFIG_NAME, [
            McpServersConfig::ITEMS_KEY => [
                [
                    'alias' => 'down',
                    'url' => 'https://down.invalid/mcp',
                    'auth_header_env_var' => '',
                    'enabled' => true,
                    'capability_prefix' => 'tool.mcp.down',
                ],
            ],
        ]);

        $registry = $this->makeRegistry();
        $source = new McpClientToolSource(new StreamableHttpMcpClient($http), $registry, $configStorage);

        // Must not throw — graceful degrade per FR-021 edge case.
        $source->bootstrap();

        self::assertSame([], iterator_to_array($this->normalise($registry->all())));
    }

    #[Test]
    public function disabledServerIsSkipped(): void
    {
        $http = new StubMcpServerHttpClient();
        $http->registerTool('echo', 'd', ['type' => 'object'], static fn(): array => ['isError' => false, 'content' => []]);

        $configStorage = new InMemoryConfigStorage();
        $configStorage->write(McpServersConfig::CONFIG_NAME, [
            McpServersConfig::ITEMS_KEY => [
                [
                    'alias' => 'stub',
                    'url' => 'https://stub.invalid/mcp',
                    'auth_header_env_var' => '',
                    'enabled' => false,
                    'capability_prefix' => 'tool.mcp.stub',
                ],
            ],
        ]);

        $registry = $this->makeRegistry();
        $source = new McpClientToolSource(new StreamableHttpMcpClient($http), $registry, $configStorage);
        $source->bootstrap();

        self::assertFalse($registry->has('stub.echo'));
    }

    #[Test]
    public function remoteToolCannotShadowAPreRegisteredLocalTool(): void
    {
        // D-17: a remote MCP server advertising a tool whose synthesised name
        // collides with an already-registered (trusted, local) tool must NOT
        // overwrite it — the local tool keeps its identity, the remote is skipped.
        $http = new StubMcpServerHttpClient();
        $http->registerTool('echo', 'Remote echo', ['type' => 'object'], static fn(): array => ['isError' => false, 'content' => []]);
        $http->registerTool('add', 'Remote add', ['type' => 'object'], static fn(): array => ['isError' => false, 'content' => []]);

        $configStorage = new InMemoryConfigStorage();
        $configStorage->write(McpServersConfig::CONFIG_NAME, [
            McpServersConfig::ITEMS_KEY => [
                [
                    'alias' => 'stub',
                    'url' => 'https://stub.invalid/mcp',
                    'auth_header_env_var' => '',
                    'enabled' => true,
                    'capability_prefix' => 'tool.mcp.stub',
                ],
            ],
        ]);

        $registry = $this->makeRegistry();

        // A trusted local tool already owns the name 'stub.echo'.
        $localImpl = new class implements AgentToolInterface {
            public function execute(array $arguments, AccountInterface $account): AgentToolResult
            {
                return AgentToolResult::error(message: 'local', summary: 'local');
            }

            public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
            {
                return AgentToolResult::error(message: 'local', summary: 'local');
            }

            public function argumentsForAudit(array $arguments): array
            {
                return $arguments;
            }

            public function inputSchema(): array
            {
                return ['type' => 'object'];
            }

            public function description(): string
            {
                return 'Local trusted echo';
            }
        };
        $registry->register(new AgentTool(
            name: 'stub.echo',
            capability: 'local.trusted.echo',
            destructive: false,
            dryRunSupported: false,
            category: 'local',
            inputSchema: ['type' => 'object'],
            impl: $localImpl,
        ));

        $source = new McpClientToolSource(new StreamableHttpMcpClient($http), $registry, $configStorage);
        $source->bootstrap();

        // The local tool survives; the remote one was skipped (not overwritten).
        self::assertSame(
            'local.trusted.echo',
            $registry->get('stub.echo')->capability,
            'a remote MCP tool must not shadow a pre-registered local tool',
        );
        self::assertFalse($registry->get('stub.echo')->destructive, 'the surviving tool is the local one');
        // A non-colliding remote tool still registers normally.
        self::assertTrue($registry->has('stub.add'));
        self::assertSame('tool.mcp.stub.add', $registry->get('stub.add')->capability);
    }

    private function makeRegistry(): AttributeToolRegistry
    {
        // AttributeToolRegistry hydrates lazily from PackageManifest.
        // An empty manifest means hand-registered tools win uncontested.
        $manifest = new PackageManifest();
        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException(sprintf('Container has no entry: %s', $id));
            }

            public function has(string $id): bool
            {
                return false;
            }
        };

        return new AttributeToolRegistry($manifest, $container);
    }

    private function makeAccount(string $permission): AccountInterface
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

    /**
     * @param iterable<AgentTool> $iter
     * @return iterable<AgentTool>
     */
    private function normalise(iterable $iter): iterable
    {
        foreach ($iter as $tool) {
            yield $tool;
        }
    }
}
