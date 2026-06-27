<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Middleware\BodySizeLimitMiddleware;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;

#[CoversClass(BodySizeLimitMiddleware::class)]
final class BodySizeLimitMiddlewareTest extends TestCase
{
    #[Test]
    public function allows_request_within_limit(): void
    {
        $middleware = new BodySizeLimitMiddleware(maxBytes: 1024);
        $request = Request::create('/test', 'POST', [], [], [], ['CONTENT_LENGTH' => '512'], 'body');
        $request->headers->set('Content-Length', '512');
        $handler = $this->passthroughHandler(new Response('ok'));

        $response = $middleware->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function returns_413_when_content_length_exceeds_limit(): void
    {
        $middleware = new BodySizeLimitMiddleware(maxBytes: 1024);
        $request = Request::create('/test', 'POST');
        $request->headers->set('Content-Length', '2048');
        $handler = $this->passthroughHandler(new Response('ok'));

        $response = $middleware->process($request, $handler);

        $this->assertSame(413, $response->getStatusCode());

        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('1.1', $body['jsonapi']['version']);
        $this->assertSame('413', $body['errors'][0]['status']);
        $this->assertSame('Payload Too Large', $body['errors'][0]['title']);
    }

    #[Test]
    public function allows_request_without_content_length(): void
    {
        $middleware = new BodySizeLimitMiddleware(maxBytes: 1024);
        $request = Request::create('/test', 'GET');
        $handler = $this->passthroughHandler(new Response('ok'));

        $response = $middleware->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function allows_request_at_exact_limit(): void
    {
        $middleware = new BodySizeLimitMiddleware(maxBytes: 1024);
        $request = Request::create('/test', 'POST');
        $request->headers->set('Content-Length', '1024');
        $handler = $this->passthroughHandler(new Response('ok'));

        $response = $middleware->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function returns_413_when_no_content_length_but_oversized_body(): void
    {
        $middleware = new BodySizeLimitMiddleware(maxBytes: 10);
        $oversizedBody = str_repeat('x', 20); // 20 bytes > 10 byte cap
        $request = Request::create('/upload', 'POST', [], [], [], [], $oversizedBody);
        $request->headers->remove('Content-Length'); // simulate chunked / no declaration
        $handler = $this->passthroughHandler(new Response('ok'));

        $response = $middleware->process($request, $handler);

        $this->assertSame(413, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('413', $body['errors'][0]['status']);
        $this->assertSame('Payload Too Large', $body['errors'][0]['title']);
    }

    #[Test]
    public function returns_413_when_content_length_understated_but_actual_body_oversized(): void
    {
        $middleware = new BodySizeLimitMiddleware(maxBytes: 10);
        $oversizedBody = str_repeat('x', 20); // 20 bytes > 10 byte cap
        $request = Request::create('/upload', 'POST', [], [], [], [], $oversizedBody);
        $request->headers->set('Content-Length', '5'); // lying / understated header
        $handler = $this->passthroughHandler(new Response('ok'));

        $response = $middleware->process($request, $handler);

        $this->assertSame(413, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('413', $body['errors'][0]['status']);
        $this->assertSame('Payload Too Large', $body['errors'][0]['title']);
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
