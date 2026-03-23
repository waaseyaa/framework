<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\SecurityHeadersMiddleware;

#[CoversClass(SecurityHeadersMiddleware::class)]
final class SecurityHeadersMiddlewareTest extends TestCase
{
    #[Test]
    public function adds_all_security_headers(): void
    {
        $middleware = new SecurityHeadersMiddleware();
        $request = Request::create('/test');
        $handler = $this->passthroughHandler(new Response('ok'));

        $response = $middleware->process($request, $handler);

        $this->assertSame("default-src 'self'", $response->headers->get('Content-Security-Policy'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('max-age=31536000', $response->headers->get('Strict-Transport-Security'));
    }

    #[Test]
    public function does_not_override_existing_headers(): void
    {
        $middleware = new SecurityHeadersMiddleware();
        $request = Request::create('/test');

        $existingResponse = new Response('ok');
        $existingResponse->headers->set('Content-Security-Policy', "default-src 'none'");
        $existingResponse->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $handler = $this->passthroughHandler($existingResponse);

        $response = $middleware->process($request, $handler);

        $this->assertSame("default-src 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
    }

    #[Test]
    public function uses_custom_csp(): void
    {
        $middleware = new SecurityHeadersMiddleware(csp: "default-src 'self'; script-src cdn.example.com");
        $request = Request::create('/test');
        $handler = $this->passthroughHandler(new Response('ok'));

        $response = $middleware->process($request, $handler);

        $this->assertSame("default-src 'self'; script-src cdn.example.com", $response->headers->get('Content-Security-Policy'));
    }

    #[Test]
    public function skips_hsts_when_disabled(): void
    {
        $middleware = new SecurityHeadersMiddleware(hstsEnabled: false);
        $request = Request::create('/test');
        $handler = $this->passthroughHandler(new Response('ok'));

        $response = $middleware->process($request, $handler);

        $this->assertFalse($response->headers->has('Strict-Transport-Security'));
    }

    #[Test]
    public function uses_custom_hsts_max_age(): void
    {
        $middleware = new SecurityHeadersMiddleware(hstsMaxAge: 3600);
        $request = Request::create('/test');
        $handler = $this->passthroughHandler(new Response('ok'));

        $response = $middleware->process($request, $handler);

        $this->assertSame('max-age=3600; includeSubDomains', $response->headers->get('Strict-Transport-Security'));
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
