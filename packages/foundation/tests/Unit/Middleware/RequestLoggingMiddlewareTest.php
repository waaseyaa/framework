<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\RequestLoggingMiddleware;

#[CoversClass(RequestLoggingMiddleware::class)]
final class RequestLoggingMiddlewareTest extends TestCase
{
    #[Test]
    public function logs_request_details(): void
    {
        $logged = [];
        $logger = static function (string $message) use (&$logged): void {
            $logged[] = $message;
        };

        $middleware = new RequestLoggingMiddleware($logger);
        $request = Request::create('/api/nodes', 'GET');
        $handler = $this->passthroughHandler(new Response('ok', 200));

        $middleware->process($request, $handler);

        $this->assertCount(1, $logged);
        $this->assertStringContainsString('GET', $logged[0]);
        $this->assertStringContainsString('/api/nodes', $logged[0]);
        $this->assertStringContainsString('200', $logged[0]);
        $this->assertMatchesRegularExpression('/\d+\.\d+ms/', $logged[0]);
    }

    #[Test]
    public function logs_non_200_status_codes(): void
    {
        $logged = [];
        $logger = static function (string $message) use (&$logged): void {
            $logged[] = $message;
        };

        $middleware = new RequestLoggingMiddleware($logger);
        $request = Request::create('/missing', 'POST');
        $handler = $this->passthroughHandler(new Response('not found', 404));

        $middleware->process($request, $handler);

        $this->assertStringContainsString('POST', $logged[0]);
        $this->assertStringContainsString('404', $logged[0]);
    }

    #[Test]
    public function uses_default_logger_without_error(): void
    {
        $middleware = new RequestLoggingMiddleware();
        $request = Request::create('/test');
        $handler = $this->passthroughHandler(new Response('ok'));

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
