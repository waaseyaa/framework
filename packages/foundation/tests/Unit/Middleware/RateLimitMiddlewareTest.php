<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\RateLimitMiddleware;
use Waaseyaa\Foundation\RateLimit\InMemoryRateLimiter;

#[CoversClass(RateLimitMiddleware::class)]
final class RateLimitMiddlewareTest extends TestCase
{
    #[Test]
    public function allows_request_within_limit(): void
    {
        $limiter = new InMemoryRateLimiter();
        $middleware = new RateLimitMiddleware(limiter: $limiter, maxAttempts: 10);
        $request = Request::create('/test', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $handler = $this->passthroughHandler(new Response('ok'));

        $response = $middleware->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('10', $response->headers->get('X-RateLimit-Limit'));
        $this->assertSame('9', $response->headers->get('X-RateLimit-Remaining'));
    }

    #[Test]
    public function returns_429_when_rate_exceeded(): void
    {
        $limiter = new InMemoryRateLimiter();
        $middleware = new RateLimitMiddleware(limiter: $limiter, maxAttempts: 2, windowSeconds: 60);
        $handler = $this->passthroughHandler(new Response('ok'));

        // Exhaust the limit.
        for ($i = 0; $i < 2; $i++) {
            $request = Request::create('/test', 'GET', [], [], [], ['REMOTE_ADDR' => '10.0.0.1']);
            $middleware->process($request, $handler);
        }

        // Third request should be rejected.
        $request = Request::create('/test', 'GET', [], [], [], ['REMOTE_ADDR' => '10.0.0.1']);
        $response = $middleware->process($request, $handler);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertNotNull($response->headers->get('Retry-After'));
        $this->assertSame('0', $response->headers->get('X-RateLimit-Remaining'));

        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('1.1', $body['jsonapi']['version']);
        $this->assertSame('429', $body['errors'][0]['status']);
    }

    #[Test]
    public function uses_unknown_key_when_no_client_ip(): void
    {
        $limiter = new InMemoryRateLimiter();
        $middleware = new RateLimitMiddleware(limiter: $limiter, maxAttempts: 1);
        $handler = $this->passthroughHandler(new Response('ok'));

        // Request without REMOTE_ADDR.
        $request = Request::create('/test');
        $response = $middleware->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
    }

    private function passthroughHandler(Response $response): HttpHandlerInterface
    {
        return new class ($response) implements HttpHandlerInterface {
            public function __construct(private readonly Response $response) {}

            public function handle(Request $request): Response
            {
                return $this->response;
            }
        };
    }
}
