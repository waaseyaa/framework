<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Middleware\DebugHeaderMiddleware;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;

#[CoversClass(DebugHeaderMiddleware::class)]
final class DebugHeaderMiddlewareTest extends TestCase
{
    #[Test]
    public function adds_time_and_memory_headers(): void
    {
        $middleware = new DebugHeaderMiddleware(startTime: microtime(true));

        $response = $middleware->process(
            Request::create('/api/nodes'),
            $this->passthrough(),
        );

        $this->assertTrue($response->headers->has('X-Debug-Time'));
        $this->assertTrue($response->headers->has('X-Debug-Memory'));
        $this->assertMatchesRegularExpression('/^\d+ms$/', $response->headers->get('X-Debug-Time'));
        $this->assertMatchesRegularExpression('/^[\d.]+MB$/', $response->headers->get('X-Debug-Memory'));
    }

    #[Test]
    public function time_header_reflects_elapsed_duration(): void
    {
        $startTime = microtime(true) - 0.042; // 42ms ago
        $middleware = new DebugHeaderMiddleware(startTime: $startTime);

        $response = $middleware->process(
            Request::create('/'),
            $this->passthrough(),
        );

        $timeMs = (int) rtrim($response->headers->get('X-Debug-Time'), 'ms');
        $this->assertGreaterThanOrEqual(42, $timeMs);
        $this->assertLessThan(200, $timeMs);
    }

    #[Test]
    public function includes_request_id_when_provided(): void
    {
        $middleware = new DebugHeaderMiddleware(
            startTime: microtime(true),
            requestId: 'req-abc-123',
        );

        $response = $middleware->process(
            Request::create('/'),
            $this->passthrough(),
        );

        $this->assertSame('req-abc-123', $response->headers->get('X-Debug-Request-Id'));
    }

    #[Test]
    public function omits_request_id_when_not_provided(): void
    {
        $middleware = new DebugHeaderMiddleware(startTime: microtime(true));

        $response = $middleware->process(
            Request::create('/'),
            $this->passthrough(),
        );

        $this->assertFalse($response->headers->has('X-Debug-Request-Id'));
    }

    #[Test]
    public function preserves_existing_response_headers(): void
    {
        $middleware = new DebugHeaderMiddleware(startTime: microtime(true));
        $next = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('OK', 200, ['X-Custom' => 'preserved']);
            }
        };

        $response = $middleware->process(Request::create('/'), $next);

        $this->assertSame('preserved', $response->headers->get('X-Custom'));
        $this->assertTrue($response->headers->has('X-Debug-Time'));
    }

    #[Test]
    public function preserves_response_status_and_content(): void
    {
        $middleware = new DebugHeaderMiddleware(startTime: microtime(true));
        $next = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('{"data":[]}', 201, ['Content-Type' => 'application/json']);
            }
        };

        $response = $middleware->process(Request::create('/'), $next);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('{"data":[]}', $response->getContent());
    }

    private function passthrough(): HttpHandlerInterface
    {
        return new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('', 200);
            }
        };
    }
}
