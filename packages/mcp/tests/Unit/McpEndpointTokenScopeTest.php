<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolInterface;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface as AgentToolRegistryInterface;
use Waaseyaa\Mcp\Auth\McpAuthInterface;
use Waaseyaa\Mcp\Auth\ScopedMcpAuthInterface;
use Waaseyaa\Mcp\Auth\ScopedPrincipal;
use Waaseyaa\Mcp\McpEndpoint;
use Waaseyaa\Mcp\McpResponse;

/**
 * Token-scope least privilege at the endpoint (#2177 F3): when the auth
 * strategy grants explicit scopes, the request's tool surface is the
 * INTERSECTION of the tier registry and those scopes — and per-tool account
 * capability enforcement still runs underneath, so a scope can only narrow,
 * never broaden.
 */
#[CoversClass(McpEndpoint::class)]
final class McpEndpointTokenScopeTest extends TestCase
{
    /** @var AccountInterface&\PHPUnit\Framework\MockObject\MockObject */
    private AccountInterface $account;

    protected function setUp(): void
    {
        $this->account = $this->createMock(AuthorizationPrincipalInterface::class);
        $this->account->method('id')->willReturn(7);
    }

    private function scopedAuth(?array $scopes): McpAuthInterface
    {
        $account = $this->account;

        return new class($account, $scopes) implements ScopedMcpAuthInterface {
            public function __construct(
                private readonly AccountInterface $account,
                private readonly ?array $scopes,
            ) {}

            public function authenticate(?string $authorizationHeader): ?AccountInterface
            {
                return $this->authenticateWithScopes($authorizationHeader)?->account;
            }

            public function authenticateWithScopes(?string $authorizationHeader): ?ScopedPrincipal
            {
                if ($authorizationHeader !== 'Bearer good') {
                    return null;
                }

                return new ScopedPrincipal($this->account, $this->scopes ?? []);
            }
        };
    }

    private function plainAuth(): McpAuthInterface
    {
        $account = $this->account;

        return new class($account) implements McpAuthInterface {
            public function __construct(private readonly AccountInterface $account) {}

            public function authenticate(?string $authorizationHeader): ?AccountInterface
            {
                return $authorizationHeader === 'Bearer good' ? $this->account : null;
            }
        };
    }

    private function endpoint(McpAuthInterface $auth): McpEndpoint
    {
        return new McpEndpoint(
            auth: $auth,
            agentRegistry: $this->registryWith([
                $this->tool('wayfinding.publish', 'cap.a'),
                $this->tool('entity.delete', 'cap.b'),
            ]),
        );
    }

    private function call(McpEndpoint $endpoint, array $payload): array
    {
        $request = HttpRequest::create(
            '/mcp/write',
            'POST',
            [], [], [],
            ['HTTP_AUTHORIZATION' => 'Bearer good'],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );

        $response = $endpoint->handle($this->account, $request);
        \assert($response instanceof McpResponse);

        return json_decode($response->body, true, 32, JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    private function listedTools(McpEndpoint $endpoint): array
    {
        $body = $this->call($endpoint, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        return array_map(static fn(array $t): string => $t['name'], $body['result']['tools'] ?? []);
    }

    #[Test]
    public function scoped_tokens_see_only_tools_whose_capability_is_in_scope(): void
    {
        $endpoint = $this->endpoint($this->scopedAuth(['cap.a']));

        self::assertSame(['wayfinding.publish'], $this->listedTools($endpoint));
    }

    #[Test]
    public function an_unscoped_auth_strategy_keeps_the_full_tier_surface(): void
    {
        $endpoint = $this->endpoint($this->plainAuth());

        self::assertSame(['wayfinding.publish', 'entity.delete'], $this->listedTools($endpoint));
    }

    #[Test]
    public function a_scoped_principal_with_no_scopes_sees_and_calls_nothing(): void
    {
        $endpoint = $this->endpoint($this->scopedAuth([]));

        self::assertSame([], $this->listedTools($endpoint));
    }

    #[Test]
    public function calling_an_out_of_scope_tool_is_refused_like_an_unknown_tool(): void
    {
        $this->account->method('hasPermission')->willReturn(true);
        $endpoint = $this->endpoint($this->scopedAuth(['cap.a']));

        $body = $this->call($endpoint, [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'entity.delete', 'arguments' => []],
        ]);

        $text = json_encode($body, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('operation', $text, 'the tool must not run');
        self::assertTrue(
            isset($body['error']) || (($body['result']['isError'] ?? false) === true),
            'an out-of-scope call must surface as an error envelope: ' . $text,
        );
    }

    #[Test]
    public function an_in_scope_tool_still_requires_the_account_capability(): void
    {
        // The account holds NO capabilities: the token scope alone must not
        // let the tool run (scopes narrow, they never broaden).
        $this->account->method('hasPermission')->willReturn(false);
        $endpoint = $this->endpoint($this->scopedAuth(['cap.a']));

        $body = $this->call($endpoint, [
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => 'wayfinding.publish', 'arguments' => []],
        ]);

        $text = json_encode($body, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('operation', $text, 'the tool must not run');
        self::assertStringContainsString('forbidden', $text);
    }

    #[Test]
    public function an_in_scope_tool_with_the_account_capability_runs(): void
    {
        $this->account->method('hasPermission')->willReturn(true);
        $endpoint = $this->endpoint($this->scopedAuth(['cap.a']));

        $body = $this->call($endpoint, [
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/call',
            'params' => ['name' => 'wayfinding.publish', 'arguments' => ['x' => 1]],
        ]);

        self::assertStringContainsString(
            'operation',
            json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    private function tool(string $name, string $capability): AgentTool
    {
        $impl = new class($capability) implements AgentToolInterface {
            public function __construct(private readonly string $capability) {}

            public function execute(array $arguments, AccountInterface $account): AgentToolResult
            {
                if (!$account->hasPermission($this->capability)) {
                    return AgentToolResult::error(message: 'forbidden', summary: 'forbidden');
                }

                return AgentToolResult::success([
                    ['type' => 'text', 'text' => \json_encode(['operation' => 'echo', ...$arguments], \JSON_THROW_ON_ERROR)],
                ]);
            }

            public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
            {
                return AgentToolResult::error('dry_run_not_supported');
            }

            public function argumentsForAudit(array $arguments): array
            {
                return $arguments;
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function description(): string
            {
                return 'Scope test tool fixture.';
            }
        };

        return new AgentTool(
            name: $name,
            capability: $capability,
            destructive: false,
            dryRunSupported: false,
            category: 'test',
            inputSchema: ['type' => 'object', 'properties' => []],
            impl: $impl,
        );
    }

    /** @param list<AgentTool> $tools */
    private function registryWith(array $tools): AgentToolRegistryInterface
    {
        return new class($tools) implements AgentToolRegistryInterface {
            /** @var array<string, AgentTool> */
            private array $map = [];

            /** @param list<AgentTool> $tools */
            public function __construct(array $tools)
            {
                foreach ($tools as $tool) {
                    $this->map[$tool->name] = $tool;
                }
            }

            public function register(AgentTool $tool): void
            {
                $this->map[$tool->name] = $tool;
            }

            public function get(string $name): AgentTool
            {
                return $this->map[$name] ?? throw ToolNotFoundException::forName($name);
            }

            public function has(string $name): bool
            {
                return isset($this->map[$name]);
            }

            public function all(): iterable
            {
                yield from array_values($this->map);
            }
        };
    }
}
