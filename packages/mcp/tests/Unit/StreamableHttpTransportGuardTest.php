<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Mcp\McpProtocol;
use Waaseyaa\Mcp\StreamableHttpRequestSnapshot;
use Waaseyaa\Mcp\StreamableHttpTransportGuard;

#[CoversClass(StreamableHttpTransportGuard::class)]
#[CoversClass(StreamableHttpRequestSnapshot::class)]
#[CoversClass(McpProtocol::class)]
final class StreamableHttpTransportGuardTest extends TestCase
{
    #[Test]
    public function compliant_post_is_admitted(): void
    {
        self::assertNull(new StreamableHttpTransportGuard()->validate($this->post()));
    }

    #[Test]
    public function post_requires_json_content_type_and_both_accept_media_types(): void
    {
        $wrongContent = $this->post(['CONTENT_TYPE' => 'text/plain']);
        self::assertSame(415, new StreamableHttpTransportGuard()->validate($wrongContent)?->statusCode);

        foreach ([
            'application/json',
            'text/event-stream',
            'application/json, text/event-stream;q=0',
        ] as $accept) {
            $request = $this->post(['HTTP_ACCEPT' => $accept]);
            self::assertSame(406, new StreamableHttpTransportGuard()->validate($request)?->statusCode);
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

        self::assertSame(406, new StreamableHttpTransportGuard()->validate($this->snapshot($request))?->statusCode);
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
        self::assertSame(403, new StreamableHttpTransportGuard()->validate($foreign)?->statusCode);
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
            self::assertSame(403, new StreamableHttpTransportGuard()->validate($request)?->statusCode);
        }
    }

    #[Test]
    public function supported_protocol_headers_are_admitted_and_unknown_versions_fail_http_400(): void
    {
        foreach (McpProtocol::SUPPORTED as $version) {
            self::assertNull(new StreamableHttpTransportGuard()->validate($this->post([
                'HTTP_MCP_PROTOCOL_VERSION' => $version,
            ])));
        }

        $response = new StreamableHttpTransportGuard()->validate($this->post([
            'HTTP_MCP_PROTOCOL_VERSION' => '2099-01-01',
        ]));
        self::assertSame(400, $response?->statusCode);
        self::assertStringContainsString('supported', $response?->body ?? '');
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
        self::assertSame(413, $response?->statusCode);
        self::assertStringContainsString('max_request_bytes', $response?->body ?? '');

        self::assertSame(413, $guard->validate($this->post(['CONTENT_LENGTH' => '999']))?->statusCode);
        self::assertSame(400, $guard->validate($this->post(['CONTENT_LENGTH' => 'not-a-number']))?->statusCode);
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
            protocolVersion: $request->headers->get('MCP-Protocol-Version'),
            contentLength: $request->headers->get('Content-Length'),
            contentType: $request->headers->get('Content-Type'),
            accept: $request->headers->get('Accept'),
            schemeAndHttpHost: $request->getSchemeAndHttpHost(),
            body: $request->getContent(),
        );
    }
}
