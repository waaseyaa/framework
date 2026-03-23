<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Middleware\ETagMiddleware;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;

#[CoversClass(ETagMiddleware::class)]
final class ETagMiddlewareTest extends TestCase
{
    #[Test]
    public function adds_etag_header_to_get_response(): void
    {
        $middleware = new ETagMiddleware();
        $request = Request::create('/test', 'GET');
        $handler = $this->passthroughHandler(new Response('hello world'));

        $response = $middleware->process($request, $handler);

        $this->assertTrue($response->headers->has('ETag'));
        $etag = $response->headers->get('ETag');
        $this->assertStringStartsWith('"', $etag);
        $this->assertStringEndsWith('"', $etag);
    }

    #[Test]
    public function returns_304_when_etag_matches(): void
    {
        $middleware = new ETagMiddleware();
        $content = 'hello world';

        // First request to get the ETag.
        $request = Request::create('/test', 'GET');
        $handler = $this->passthroughHandler(new Response($content));
        $firstResponse = $middleware->process($request, $handler);
        $etag = $firstResponse->headers->get('ETag');

        // Second request with If-None-Match.
        $request = Request::create('/test', 'GET');
        $request->headers->set('If-None-Match', $etag);
        $handler = $this->passthroughHandler(new Response($content));

        $response = $middleware->process($request, $handler);

        $this->assertSame(304, $response->getStatusCode());
        $this->assertSame($etag, $response->headers->get('ETag'));
    }

    #[Test]
    public function does_not_add_etag_for_post_requests(): void
    {
        $middleware = new ETagMiddleware();
        $request = Request::create('/test', 'POST');
        $handler = $this->passthroughHandler(new Response('created', 201));

        $response = $middleware->process($request, $handler);

        $this->assertFalse($response->headers->has('ETag'));
    }

    #[Test]
    public function does_not_add_etag_for_error_responses(): void
    {
        $middleware = new ETagMiddleware();
        $request = Request::create('/test', 'GET');
        $handler = $this->passthroughHandler(new Response('not found', 404));

        $response = $middleware->process($request, $handler);

        $this->assertFalse($response->headers->has('ETag'));
    }

    #[Test]
    public function does_not_add_etag_for_empty_body(): void
    {
        $middleware = new ETagMiddleware();
        $request = Request::create('/test', 'GET');
        $handler = $this->passthroughHandler(new Response(''));

        $response = $middleware->process($request, $handler);

        $this->assertFalse($response->headers->has('ETag'));
    }

    #[Test]
    public function returns_200_when_etag_does_not_match(): void
    {
        $middleware = new ETagMiddleware();
        $request = Request::create('/test', 'GET');
        $request->headers->set('If-None-Match', '"stale-etag"');
        $handler = $this->passthroughHandler(new Response('new content'));

        $response = $middleware->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->headers->has('ETag'));
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
