<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Auth\AtomicRateLimiterInterface;
use Waaseyaa\Mcp\McpErrorCode;
use Waaseyaa\Mcp\Auth\McpAuthInterface;
use Waaseyaa\Mcp\McpEndpoint;
use Waaseyaa\Mcp\McpResponse;

/**
 * Per-principal MCP rate limiting (#2136 WP3): keyed by tier + principal id,
 * a JSON-RPC rate-limit error + HTTP 429 when exceeded, and a sanitized fail-closed 503
 * when the limiter substrate cannot make a durable decision.
 */
#[CoversClass(McpEndpoint::class)]
final class McpEndpointRateLimitTest extends TestCase
{
    private McpAuthInterface $auth;

    protected function setUp(): void
    {
        $this->auth = $this->createStub(McpAuthInterface::class);
        $this->auth->method('authenticate')->willReturnCallback(
            fn(?string $header): ?AuthorizationPrincipalInterface => $this->principal(
                (int) str_replace('Bearer uid-', '', (string) $header),
            ),
        );
    }

    private function principal(int $uid): AuthorizationPrincipalInterface
    {
        $account = $this->createStub(AuthorizationPrincipalInterface::class);
        $account->method('id')->willReturn($uid);
        $account->method('isAuthenticated')->willReturn(true);

        return $account;
    }

    private function endpoint(?AtomicRateLimiterInterface $limiter, int $max = 2, string $tier = 'write'): McpEndpoint
    {
        $registry = $this->createStub(ToolRegistryInterface::class);
        $registry->method('all')->willReturn([]);

        return new McpEndpoint(
            auth: $this->auth,
            agentRegistry: $registry,
            rateLimiter: $limiter,
            rateLimitMaxRequests: $max,
            rateLimitWindowSeconds: 60,
            rateLimitTier: $tier,
        );
    }

    private function ping(McpEndpoint $endpoint, int $uid): McpResponse
    {
        $request = HttpRequest::create(
            '/mcp',
            'POST',
            [],
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer uid-' . $uid],
            json_encode(['jsonrpc' => '2.0', 'method' => 'ping', 'id' => 1]),
        );

        return $endpoint->handle($this->principal($uid), $request);
    }

    /** In-memory limiter recording keys and enforcing counts. */
    private function inMemoryLimiter(): AtomicRateLimiterInterface
    {
        return new class implements AtomicRateLimiterInterface {
            /** @var array<string, int> */
            public array $hits = [];

            public function consume(string $key, int $maxAttempts, int $decaySeconds): bool
            {
                $this->hit($key, $decaySeconds);

                return $this->hits[$key] <= $maxAttempts;
            }

            public function hit(string $key, int $decaySeconds): void
            {
                $this->hits[$key] = ($this->hits[$key] ?? 0) + 1;
            }

            public function tooManyAttempts(string $key, int $maxAttempts): bool
            {
                return ($this->hits[$key] ?? 0) >= $maxAttempts;
            }

            public function attempts(string $key): int
            {
                return $this->hits[$key] ?? 0;
            }

            public function remaining(string $key, int $maxAttempts): int
            {
                return max(0, $maxAttempts - $this->attempts($key));
            }

            public function clear(string $key): void
            {
                unset($this->hits[$key]);
            }
        };
    }

    #[Test]
    public function rate_limiting_is_off_by_default(): void
    {
        $endpoint = new McpEndpoint(
            auth: $this->auth,
            agentRegistry: $this->createStub(ToolRegistryInterface::class),
        );
        for ($i = 0; $i < 25; $i++) {
            self::assertSame(200, $this->ping($endpoint, 1)->statusCode);
        }
    }

    #[Test]
    public function requests_over_the_limit_get_json_rpc_32029_and_http_429(): void
    {
        $endpoint = $this->endpoint($this->inMemoryLimiter(), max: 2);

        self::assertSame(200, $this->ping($endpoint, 7)->statusCode);
        self::assertSame(200, $this->ping($endpoint, 7)->statusCode);

        $limited = $this->ping($endpoint, 7);
        self::assertSame(429, $limited->statusCode);
        $body = json_decode($limited->body, true);
        self::assertSame(McpErrorCode::RATE_LIMIT_EXCEEDED, $body['error']['code']);
        self::assertSame(60, $body['error']['data']['retry_after_seconds']);
        self::assertSame('60', $limited->headers['Retry-After']);
    }

    #[Test]
    public function limits_are_keyed_per_principal_and_tier(): void
    {
        $limiter = $this->inMemoryLimiter();
        $endpoint = $this->endpoint($limiter, max: 2, tier: 'write');

        $this->ping($endpoint, 7);
        $this->ping($endpoint, 7);
        self::assertSame(429, $this->ping($endpoint, 7)->statusCode);

        // A different principal is unaffected.
        self::assertSame(200, $this->ping($endpoint, 8)->statusCode);
        self::assertArrayHasKey('mcp:write:7', $limiter->hits);
        self::assertArrayHasKey('mcp:write:8', $limiter->hits);
    }

    #[Test]
    public function unauthorized_requests_do_not_consume_the_budget(): void
    {
        $limiter = $this->inMemoryLimiter();
        $endpoint = $this->endpoint($limiter, max: 2);

        $request = HttpRequest::create('/mcp', 'POST', [], [], [], [], '{}');
        $auth = $this->createStub(McpAuthInterface::class);
        $auth->method('authenticate')->willReturn(null);
        $anonEndpoint = new McpEndpoint(
            auth: $auth,
            agentRegistry: $this->createStub(ToolRegistryInterface::class),
            rateLimiter: $limiter,
            rateLimitMaxRequests: 2,
        );
        self::assertSame(401, $anonEndpoint->handle($this->principal(1), $request)->statusCode);
        self::assertSame([], $limiter->hits);
    }

    #[Test]
    public function limiter_infrastructure_failure_fails_closed_without_leaking_the_cause(): void
    {
        $broken = new class implements AtomicRateLimiterInterface {
            public function consume(string $key, int $maxAttempts, int $decaySeconds): bool
            {
                throw new \RuntimeException('limiter db down');
            }
            public function hit(string $key, int $decaySeconds): void
            {
                throw new \RuntimeException('limiter db down');
            }

            public function tooManyAttempts(string $key, int $maxAttempts): bool
            {
                throw new \RuntimeException('limiter db down');
            }

            public function attempts(string $key): int
            {
                return 0;
            }

            public function remaining(string $key, int $maxAttempts): int
            {
                return $maxAttempts;
            }

            public function clear(string $key): void {}
        };

        $endpoint = $this->endpoint($broken, max: 1);
        $first = $this->ping($endpoint, 7);
        $second = $this->ping($endpoint, 7);
        self::assertSame(503, $first->statusCode);
        self::assertSame(503, $second->statusCode);
        self::assertStringNotContainsString('limiter db down', $first->body);
        self::assertArrayNotHasKey('Retry-After', $first->headers);
        self::assertArrayNotHasKey('Retry-After', $second->headers);
    }
}
