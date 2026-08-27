<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Http\Refusal\RefusalEnvelope;
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

    /**
     * #2594: an endpoint that advertises a JSON-RPC transport must have its
     * oversize refusal rendered in that transport's vocabulary. This is the
     * posture where PHP's own `post_max_size` exceeds the configured cap, so
     * the declared `Content-Length` is what trips the fast path.
     */
    #[Test]
    public function refuses_in_the_transport_the_matched_route_declares_on_the_fast_path(): void
    {
        $middleware = new BodySizeLimitMiddleware(maxBytes: 1024);
        $request = Request::create('/mcp', 'POST');
        $request->headers->set('Content-Length', '2048');
        $this->declareJsonRpcTransport($request);

        $response = $middleware->process($request, $this->passthroughHandler(new Response('ok')));

        $this->assertSame(413, $response->getStatusCode());
        $this->assertSame(
            [
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32043,
                    'message' => 'Request body exceeds maximum size',
                    'data' => ['max_request_bytes' => 1024],
                ],
                'id' => null,
            ],
            json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * #2594, second posture: at PHP defaults the body is truncated, so the
     * guard trips on the actual read rather than the declared length. The
     * refusal envelope must be the same either way.
     */
    #[Test]
    public function refuses_in_the_transport_the_matched_route_declares_on_the_actual_read(): void
    {
        $middleware = new BodySizeLimitMiddleware(maxBytes: 10);
        $request = Request::create('/mcp', 'POST', [], [], [], [], str_repeat('x', 20));
        $request->headers->remove('Content-Length');
        $this->declareJsonRpcTransport($request);

        $response = $middleware->process($request, $this->passthroughHandler(new Response('ok')));

        $this->assertSame(413, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(-32043, $body['error']['code']);
        $this->assertSame(['max_request_bytes' => 10], $body['error']['data']);
    }

    /**
     * The seam negotiates the envelope, never the cap: declaring a transport
     * must not widen, narrow, or bypass the limit, and a request within the cap
     * must still reach the handler untouched.
     */
    #[Test]
    public function declaring_a_transport_does_not_change_the_cap(): void
    {
        $middleware = new BodySizeLimitMiddleware(maxBytes: 10);

        $atLimit = Request::create('/mcp', 'POST', [], [], [], [], str_repeat('x', 10));
        $this->declareJsonRpcTransport($atLimit);
        self::assertSame(
            200,
            $middleware->process($atLimit, $this->passthroughHandler(new Response('ok')))->getStatusCode(),
            'a declared transport must not narrow the cap',
        );

        $overLimit = Request::create('/mcp', 'POST', [], [], [], [], str_repeat('x', 11));
        $this->declareJsonRpcTransport($overLimit);
        self::assertSame(
            413,
            $middleware->process($overLimit, $this->passthroughHandler(new Response('ok')))->getStatusCode(),
            'a declared transport must not widen the cap',
        );
    }

    #[Test]
    public function a_route_that_declares_no_transport_keeps_the_json_api_envelope(): void
    {
        $middleware = new BodySizeLimitMiddleware(maxBytes: 1024);
        $request = Request::create('/api/nodes', 'POST');
        $request->headers->set('Content-Length', '2048');
        $request->attributes->set(
            RefusalEnvelope::REQUEST_ATTRIBUTE,
            RefusalEnvelope::fromRouteOptions([]),
        );

        $response = $middleware->process($request, $this->passthroughHandler(new Response('ok')));

        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Payload Too Large', $body['errors'][0]['title']);
    }

    private function declareJsonRpcTransport(Request $request): void
    {
        $request->attributes->set(
            RefusalEnvelope::REQUEST_ATTRIBUTE,
            RefusalEnvelope::fromRouteOptions([
                RefusalEnvelope::TRANSPORT_OPTION => RefusalEnvelope::TRANSPORT_JSON_RPC,
                RefusalEnvelope::CODES_OPTION => [
                    RefusalEnvelope::REASON_PAYLOAD_TOO_LARGE => -32043,
                    RefusalEnvelope::REASON_PARSE_ERROR => -32700,
                ],
            ]),
        );
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
