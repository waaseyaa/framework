<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Middleware\CompressionMiddleware;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;

#[CoversClass(CompressionMiddleware::class)]
final class CompressionMiddlewareTest extends TestCase
{
    #[Test]
    public function compresses_response_when_accepted_and_large_enough(): void
    {
        $middleware = new CompressionMiddleware(minimumSize: 10);
        $content = str_repeat('Hello World! ', 100);
        $request = Request::create('/test');
        $request->headers->set('Accept-Encoding', 'gzip, deflate');
        $handler = $this->passthroughHandler(new Response($content));

        $response = $middleware->process($request, $handler);

        $this->assertSame('gzip', $response->headers->get('Content-Encoding'));
        $this->assertSame(gzdecode($response->getContent()), $content);
    }

    #[Test]
    public function does_not_compress_when_gzip_not_accepted(): void
    {
        $middleware = new CompressionMiddleware(minimumSize: 10);
        $content = str_repeat('a', 100);
        $request = Request::create('/test');
        $request->headers->set('Accept-Encoding', 'deflate');
        $handler = $this->passthroughHandler(new Response($content));

        $response = $middleware->process($request, $handler);

        $this->assertFalse($response->headers->has('Content-Encoding'));
        $this->assertSame($content, $response->getContent());
    }

    #[Test]
    public function does_not_compress_small_responses(): void
    {
        $middleware = new CompressionMiddleware(minimumSize: 1024);
        $request = Request::create('/test');
        $request->headers->set('Accept-Encoding', 'gzip');
        $handler = $this->passthroughHandler(new Response('small'));

        $response = $middleware->process($request, $handler);

        $this->assertFalse($response->headers->has('Content-Encoding'));
        $this->assertSame('small', $response->getContent());
    }

    #[Test]
    public function does_not_double_compress(): void
    {
        $middleware = new CompressionMiddleware(minimumSize: 10);
        $content = str_repeat('a', 100);
        $existingResponse = new Response($content);
        $existingResponse->headers->set('Content-Encoding', 'br');

        $request = Request::create('/test');
        $request->headers->set('Accept-Encoding', 'gzip');
        $handler = $this->passthroughHandler($existingResponse);

        $response = $middleware->process($request, $handler);

        $this->assertSame('br', $response->headers->get('Content-Encoding'));
    }

    #[Test]
    public function handles_empty_body(): void
    {
        $middleware = new CompressionMiddleware(minimumSize: 10);
        $request = Request::create('/test');
        $request->headers->set('Accept-Encoding', 'gzip');
        $handler = $this->passthroughHandler(new Response(''));

        $response = $middleware->process($request, $handler);

        $this->assertFalse($response->headers->has('Content-Encoding'));
    }

    #[Test]
    public function preserves_pre_existing_vary_and_appends_accept_encoding(): void
    {
        $middleware = new CompressionMiddleware(minimumSize: 10);
        $content = str_repeat('Hello World! ', 100);
        $existingResponse = new Response($content);
        $existingResponse->headers->set('Vary', 'Cookie');

        $request = Request::create('/test');
        $request->headers->set('Accept-Encoding', 'gzip');
        $handler = $this->passthroughHandler($existingResponse);

        $response = $middleware->process($request, $handler);

        $vary = $response->headers->get('Vary');
        $this->assertStringContainsString('Cookie', $vary);
        $this->assertStringContainsString('Accept-Encoding', $vary);
    }

    #[Test]
    public function sets_vary_accept_encoding_when_no_pre_existing_vary(): void
    {
        $middleware = new CompressionMiddleware(minimumSize: 10);
        $content = str_repeat('Hello World! ', 100);
        $request = Request::create('/test');
        $request->headers->set('Accept-Encoding', 'gzip');
        $handler = $this->passthroughHandler(new Response($content));

        $response = $middleware->process($request, $handler);

        $this->assertSame('Accept-Encoding', $response->headers->get('Vary'));
    }

    #[Test]
    public function does_not_duplicate_accept_encoding_when_already_present_case_insensitively(): void
    {
        $middleware = new CompressionMiddleware(minimumSize: 10);
        $content = str_repeat('Hello World! ', 100);
        $existingResponse = new Response($content);
        $existingResponse->headers->set('Vary', 'accept-encoding');

        $request = Request::create('/test');
        $request->headers->set('Accept-Encoding', 'gzip');
        $handler = $this->passthroughHandler($existingResponse);

        $response = $middleware->process($request, $handler);

        $vary = $response->headers->get('Vary');
        $this->assertSame('accept-encoding', $vary);
    }

    #[Test]
    public function leaves_vary_wildcard_unchanged(): void
    {
        $middleware = new CompressionMiddleware(minimumSize: 10);
        $content = str_repeat('Hello World! ', 100);
        $existingResponse = new Response($content);
        $existingResponse->headers->set('Vary', '*');

        $request = Request::create('/test');
        $request->headers->set('Accept-Encoding', 'gzip');
        $handler = $this->passthroughHandler($existingResponse);

        $response = $middleware->process($request, $handler);

        $this->assertSame('*', $response->headers->get('Vary'));
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
