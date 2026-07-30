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
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Auth\RateLimiterInterface;
use Waaseyaa\Mcp\Auth\McpAuthInterface;
use Waaseyaa\Mcp\McpEndpoint;
use Waaseyaa\Mcp\McpResponse;

/**
 * #2145 ordering guarantees: schema enforcement slots in AFTER
 * authentication and rate limiting, and malformed `params` shapes are
 * rejected at the JSON-RPC layer (-32602) instead of raising TypeErrors
 * — malformed input must never reach a handler, and the established
 * auth/rate-limit ordering is unchanged.
 */
#[CoversClass(McpEndpoint::class)]
final class McpEndpointSchemaOrderingTest extends TestCase
{
    /** @var \ArrayObject<int, array<string, mixed>> */
    private \ArrayObject $handlerSink;

    protected function setUp(): void
    {
        $this->handlerSink = new \ArrayObject();
    }

    public const array SCHEMA = [
        'type' => 'object',
        'properties' => ['id' => ['type' => 'string']],
        'required' => ['id'],
        'additionalProperties' => false,
    ];

    private function registry(): ToolRegistryInterface
    {
        $impl = new class ($this->handlerSink) implements AgentToolInterface {
            /** @param \ArrayObject<int, array<string, mixed>> $calls */
            public function __construct(private readonly \ArrayObject $calls) {}

            public function execute(array $arguments, AccountInterface $account): AgentToolResult
            {
                $this->calls->append($arguments);

                return AgentToolResult::success([['type' => 'text', 'text' => '{"ok":true}']]);
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
                return McpEndpointSchemaOrderingTest::SCHEMA;
            }

            public function description(): string
            {
                return 'Ordering fixture tool.';
            }
        };

        $tool = new AgentTool(
            name: 'ordering.tool',
            capability: 'tool.test',
            destructive: false,
            dryRunSupported: false,
            category: 'test',
            inputSchema: self::SCHEMA,
            impl: $impl,
        );

        return new class ($tool) implements ToolRegistryInterface {
            public function __construct(private readonly AgentTool $tool) {}

            public function register(AgentTool $tool): void {}

            public function get(string $name): AgentTool
            {
                return $name === $this->tool->name ? $this->tool : throw new ToolNotFoundException($name);
            }

            public function has(string $name): bool
            {
                return $name === $this->tool->name;
            }

            public function all(): iterable
            {
                return [$this->tool->name => $this->tool];
            }
        };
    }

    private function account(): AccountInterface
    {
        $account = $this->createMock(AuthorizationPrincipalInterface::class);
        $account->method('id')->willReturn(1);

        return $account;
    }

    private function endpoint(bool $authenticates, ?RateLimiterInterface $limiter = null): McpEndpoint
    {
        $auth = $this->createMock(McpAuthInterface::class);
        $auth->method('authenticate')->willReturn($authenticates ? $this->account() : null);

        return new McpEndpoint(
            auth: $auth,
            agentRegistry: $this->registry(),
            rateLimiter: $limiter,
            rateLimitMaxRequests: $limiter !== null ? 1 : 0,
        );
    }

    private function call(McpEndpoint $endpoint, array $params): McpResponse
    {
        $body = \json_encode([
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => $params,
            'id' => 1,
        ], \JSON_THROW_ON_ERROR);

        return $endpoint->handle($this->account(), HttpRequest::create('/mcp', 'POST', [], [], [], [], $body));
    }

    #[Test]
    public function authentication_precedes_schema_validation(): void
    {
        $endpoint = $this->endpoint(authenticates: false);

        $response = $this->call($endpoint, ['name' => 'ordering.tool', 'arguments' => ['bogus' => 1]]);
        $decoded = \json_decode($response->body, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(401, $response->statusCode);
        self::assertSame(-32001, $decoded['error']['code']);
        self::assertStringNotContainsString('VALIDATION_FAILED', $response->body);
        self::assertSame([], $this->handlerSink->getArrayCopy());
    }

    #[Test]
    public function rate_limiting_precedes_schema_validation(): void
    {
        $limiter = new class implements RateLimiterInterface {
            public function hit(string $key, int $decaySeconds): void {}

            public function tooManyAttempts(string $key, int $maxAttempts): bool
            {
                return true;
            }

            public function attempts(string $key): int
            {
                return 99;
            }

            public function remaining(string $key, int $maxAttempts): int
            {
                return 0;
            }

            public function clear(string $key): void {}
        };

        $endpoint = $this->endpoint(authenticates: true, limiter: $limiter);

        $response = $this->call($endpoint, ['name' => 'ordering.tool', 'arguments' => ['bogus' => 1]]);
        $decoded = \json_decode($response->body, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(429, $response->statusCode);
        self::assertSame(-32029, $decoded['error']['code']);
        self::assertSame([], $this->handlerSink->getArrayCopy());
    }

    #[Test]
    public function authenticated_schema_invalid_calls_return_the_validation_envelope(): void
    {
        $endpoint = $this->endpoint(authenticates: true);

        $response = $this->call($endpoint, ['name' => 'ordering.tool', 'arguments' => []]);
        $decoded = \json_decode($response->body, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->statusCode);
        self::assertTrue($decoded['result']['isError'] ?? false);
        $envelope = \json_decode((string) $decoded['result']['content'][0]['text'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('VALIDATION_FAILED', $envelope['code']);
        self::assertSame([], $this->handlerSink->getArrayCopy());
    }

    #[Test]
    public function non_object_arguments_are_rejected_as_invalid_params(): void
    {
        $endpoint = $this->endpoint(authenticates: true);

        $response = $this->call($endpoint, ['name' => 'ordering.tool', 'arguments' => 'not-an-object']);
        $decoded = \json_decode($response->body, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(-32602, $decoded['error']['code'] ?? null);
        self::assertSame([], $this->handlerSink->getArrayCopy());
    }

    #[Test]
    public function a_non_string_tool_name_is_rejected_as_invalid_params(): void
    {
        $endpoint = $this->endpoint(authenticates: true);

        $response = $this->call($endpoint, ['name' => ['nested' => 'array'], 'arguments' => []]);
        $decoded = \json_decode($response->body, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(-32602, $decoded['error']['code'] ?? null);
        self::assertSame([], $this->handlerSink->getArrayCopy());
    }

    #[Test]
    public function non_array_params_are_rejected_as_invalid_params(): void
    {
        $endpoint = $this->endpoint(authenticates: true);

        $body = \json_encode([
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => 'garbage',
            'id' => 1,
        ], \JSON_THROW_ON_ERROR);
        $response = $endpoint->handle($this->account(), HttpRequest::create('/mcp', 'POST', [], [], [], [], $body));
        $decoded = \json_decode($response->body, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(-32602, $decoded['error']['code'] ?? null);
        self::assertSame([], $this->handlerSink->getArrayCopy());
    }
}
