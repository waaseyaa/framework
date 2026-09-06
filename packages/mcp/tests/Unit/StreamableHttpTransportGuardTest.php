<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Foundation\Middleware\BodySizeLimitMiddleware;
use Waaseyaa\Mcp\McpErrorCode;
use Waaseyaa\Mcp\McpResponse;
use Waaseyaa\Mcp\StreamableHttpRequestSnapshot;
use Waaseyaa\Mcp\StreamableHttpTransportGuard;

#[CoversClass(StreamableHttpTransportGuard::class)]
#[CoversClass(McpErrorCode::class)]
#[CoversClass(StreamableHttpRequestSnapshot::class)]
final class StreamableHttpTransportGuardTest extends TestCase
{
    #[Test]
    public function compliant_post_is_admitted(): void
    {
        self::assertNull(new StreamableHttpTransportGuard()->validate($this->post()));
    }

    #[Test]
    public function advertisedDefaultMatchesTheKernelBodyLimit(): void
    {
        $kernelDefault = (new \ReflectionClass(BodySizeLimitMiddleware::class))
            ->getConstructor()
            ?->getParameters()[0]
            ->getDefaultValue();

        self::assertSame(1_048_576, $kernelDefault);
        self::assertSame($kernelDefault, StreamableHttpTransportGuard::DEFAULT_MAX_REQUEST_BYTES);
    }

    #[Test]
    public function post_requires_json_content_type_and_both_accept_media_types(): void
    {
        $wrongContent = $this->post(['CONTENT_TYPE' => 'text/plain']);
        self::assertSame(
            [415, McpErrorCode::UNSUPPORTED_CONTENT_TYPE],
            $this->refusal(new StreamableHttpTransportGuard()->validate($wrongContent)),
        );

        foreach ([
            'application/json',
            'text/event-stream',
            'application/json, text/event-stream;q=0',
        ] as $accept) {
            $request = $this->post(['HTTP_ACCEPT' => $accept]);
            self::assertSame(
                [406, McpErrorCode::UNACCEPTABLE_ACCEPT],
                $this->refusal(new StreamableHttpTransportGuard()->validate($request)),
            );
        }
    }

    #[Test]
    public function get_honestly_refuses_sse_streaming_with_405(): void
    {
        $request = Request::create('/mcp', 'GET', server: [
            'HTTP_ACCEPT' => 'text/event-stream',
        ]);

        $response = new StreamableHttpTransportGuard()->validate($this->snapshot($request));

        self::assertSame(405, $response?->statusCode);
        self::assertSame('', $response?->body);
        self::assertSame('POST', $response?->headers['Allow']);
    }

    #[Test]
    public function get_without_sse_accept_is_not_acceptable(): void
    {
        $request = Request::create('/mcp', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertSame(
            [406, McpErrorCode::UNACCEPTABLE_ACCEPT],
            $this->refusal(new StreamableHttpTransportGuard()->validate($this->snapshot($request))),
        );
    }

    #[Test]
    public function absent_and_same_origin_headers_are_allowed_but_foreign_origins_are_forbidden(): void
    {
        self::assertNull(new StreamableHttpTransportGuard()->validate($this->post()));
        self::assertNull(new StreamableHttpTransportGuard()->validate($this->post([
            'HTTP_HOST' => 'cms.example.test',
            'HTTPS' => 'on',
            'HTTP_ORIGIN' => 'https://cms.example.test',
        ])));

        $foreign = $this->post([
            'HTTP_HOST' => 'cms.example.test',
            'HTTPS' => 'on',
            'HTTP_ORIGIN' => 'https://evil.example',
        ]);
        self::assertSame(
            [403, McpErrorCode::FORBIDDEN_ORIGIN],
            $this->refusal(new StreamableHttpTransportGuard()->validate($foreign)),
        );
    }

    #[Test]
    public function explicit_origin_allowlist_is_exact_and_normalized(): void
    {
        $guard = new StreamableHttpTransportGuard(['https://editor.example:443/']);
        $request = $this->post([
            'HTTP_HOST' => 'cms.example.test',
            'HTTPS' => 'on',
            'HTTP_ORIGIN' => 'https://editor.example',
        ]);

        self::assertNull($guard->validate($request));
    }

    #[Test]
    public function malformed_or_credential_bearing_origins_are_refused(): void
    {
        foreach (['null', 'https://user:pass@example.test', 'https://example.test/path', 'https://a, https://b'] as $origin) {
            $request = $this->post(['HTTP_ORIGIN' => $origin]);
            self::assertSame(
                [403, McpErrorCode::FORBIDDEN_ORIGIN],
                $this->refusal(new StreamableHttpTransportGuard()->validate($request)),
            );
        }
    }

    #[Test]
    public function protocol_header_is_admitted_for_post_parse_era_validation(): void
    {
        self::assertNull(new StreamableHttpTransportGuard()->validate($this->post([
            'HTTP_MCP_PROTOCOL_VERSION' => '2099-01-01',
        ])));
    }

    #[Test]
    public function oversized_or_deceptively_declared_request_bodies_are_refused_before_dispatch(): void
    {
        $guard = new StreamableHttpTransportGuard(maxRequestBytes: 8);

        $oversized = Request::create('/mcp', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
        ], content: '{"too":"large"}');
        $response = $guard->validate($this->snapshot($oversized));
        self::assertSame([413, McpErrorCode::REQUEST_TOO_LARGE], $this->refusal($response));
        self::assertStringContainsString('max_request_bytes', $response?->body ?? '');

        self::assertSame(
            [413, McpErrorCode::REQUEST_TOO_LARGE],
            $this->refusal($guard->validate($this->post(['CONTENT_LENGTH' => '999']))),
        );
        self::assertSame(
            [400, -32600],
            $this->refusal($guard->validate($this->post(['CONTENT_LENGTH' => 'not-a-number']))),
        );
    }

    /**
     * Neither GET nor POST: 405 with an empty body, like the GET-SSE refusal.
     *
     * These two are the only refusals with no JSON-RPC envelope, which is why
     * they are asserted directly rather than through refusal(): there is no
     * error code to pin, and inventing one would be the wrong fix — a method
     * the transport does not implement is an HTTP-level answer.
     */
    #[Test]
    public function methods_other_than_post_and_get_are_refused_with_405(): void
    {
        foreach (['PUT', 'DELETE', 'PATCH'] as $method) {
            $request = Request::create('/mcp', $method, server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json, text/event-stream',
            ], content: '{}');

            $response = new StreamableHttpTransportGuard()->validate($this->snapshot($request));

            self::assertSame(405, $response?->statusCode, $method);
            self::assertSame('', $response?->body, $method);
            self::assertSame('POST', $response?->headers['Allow'] ?? null, $method);
        }
    }

    /**
     * The HTTP status *and* the JSON-RPC error code a refusal puts on the wire.
     *
     * Asserting the status alone leaves the code free to drift: every transport
     * refusal below is the only place its code is exercised behaviourally, so a
     * mis-assignment — an `Origin` refusal answering the `Content-Type` code —
     * changed nothing any test could see. The code is the half a JSON-RPC client
     * branches on (#2561), so it is asserted together with the status it travels
     * with, never on its own.
     *
     * @return array{int, int|null}
     */
    private function refusal(?McpResponse $response): array
    {
        self::assertNotNull($response, 'Expected the guard to refuse this request.');

        $decoded = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('2.0', $decoded['jsonrpc'] ?? null, 'Refusal is not a JSON-RPC envelope.');
        self::assertArrayHasKey('error', $decoded);
        self::assertIsArray($decoded['error']);
        // Asserted, not coalesced: a missing code must fail as "no code", not
        // be smuggled into the tuple as null and reported as a value mismatch.
        self::assertArrayHasKey('code', $decoded['error']);

        return [$response->statusCode, $decoded['error']['code']];
    }

    /** @param array<string, string> $overrides */
    private function post(array $overrides = []): StreamableHttpRequestSnapshot
    {
        return $this->snapshot(Request::create('/mcp', 'POST', server: $overrides + [
            'CONTENT_TYPE' => 'application/json; charset=utf-8',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
        ], content: '{}'));
    }

    private function snapshot(Request $request): StreamableHttpRequestSnapshot
    {
        return new StreamableHttpRequestSnapshot(
            method: $request->getMethod(),
            origin: $request->headers->get('Origin'),
            contentLength: $request->headers->get('Content-Length'),
            contentType: $request->headers->get('Content-Type'),
            accept: $request->headers->get('Accept'),
            schemeAndHttpHost: $request->getSchemeAndHttpHost(),
            body: $request->getContent(),
        );
    }
}
