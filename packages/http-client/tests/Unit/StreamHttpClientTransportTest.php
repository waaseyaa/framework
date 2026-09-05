<?php

declare(strict_types=1);

namespace Waaseyaa\HttpClient\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\HttpClient\HttpRequestException;
use Waaseyaa\HttpClient\StreamHttpClient;
use Waaseyaa\HttpClient\Tests\Support\LocalHttpServer;
use Waaseyaa\HttpClient\Tests\Support\RawHttpServer;

/**
 * Transport-level hardening tests for StreamHttpClient (credential-leak +
 * worker-safety). Config-level guarantees are pinned by white-box assertions on
 * the built stream context; behaviour is pinned against a real `php -S` server.
 */
#[CoversClass(StreamHttpClient::class)]
final class StreamHttpClientTransportTest extends TestCase
{
    /** @var list<LocalHttpServer> */
    private array $servers = [];

    /** @var list<RawHttpServer> */
    private array $rawServers = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        $this->servers = [];
        foreach ($this->rawServers as $server) {
            $server->stop();
        }
        $this->rawServers = [];
    }

    private function startServer(): LocalHttpServer
    {
        $server = new LocalHttpServer();
        $this->servers[] = $server;

        return $server;
    }

    private function startRaw(string $httpResponse, int $splitAt = 0, int $delayUs = 0): RawHttpServer
    {
        $server = new RawHttpServer($httpResponse, $splitAt, $delayUs);
        $this->rawServers[] = $server;

        return $server;
    }

    /**
     * @return array{http: array<string, mixed>, ssl: array<string, mixed>}
     */
    private function contextOptions(StreamHttpClient $client): array
    {
        $ref = new \ReflectionMethod($client, 'buildContextOptions');

        return $ref->invoke($client, 'GET', ['Authorization: Bearer x'], null);
    }

    // ---- M1 + m1: config-level guarantees ---------------------------------

    #[Test]
    public function disablesRedirectFollowing(): void
    {
        $opts = $this->contextOptions(new StreamHttpClient());

        self::assertSame(0, $opts['http']['follow_location']);
        self::assertSame(0, $opts['http']['max_redirects']);
    }

    #[Test]
    public function pinsTlsVerification(): void
    {
        $opts = $this->contextOptions(new StreamHttpClient());

        self::assertTrue($opts['ssl']['verify_peer']);
        self::assertTrue($opts['ssl']['verify_peer_name']);
        self::assertFalse($opts['ssl']['allow_self_signed']);
    }

    #[Test]
    public function appliesReadTimeout(): void
    {
        $opts = $this->contextOptions(new StreamHttpClient(timeout: 7.5));

        self::assertSame(7.5, $opts['http']['timeout']);
    }

    // ---- n1: CRLF header-injection guard ----------------------------------

    #[Test]
    public function rejectsCrlfInHeaderValue(): void
    {
        $client = new StreamHttpClient();

        $this->expectException(HttpRequestException::class);
        $this->expectExceptionMessageMatches('/CR\/LF/');
        $client->get('https://example.test/', ['X-Test' => "ok\r\nInjected: 1"]);
    }

    #[Test]
    public function rejectsCrlfInHeaderName(): void
    {
        $client = new StreamHttpClient();

        $this->expectException(HttpRequestException::class);
        $client->get('https://example.test/', ["Bad\r\nName" => 'value']);
    }

    // ---- M1: only http/https ----------------------------------------------

    #[Test]
    public function rejectsNonHttpScheme(): void
    {
        $client = new StreamHttpClient();

        $this->expectException(HttpRequestException::class);
        $this->expectExceptionMessageMatches('/scheme/');
        $client->request('GET', 'file:///etc/passwd');
    }

    // ---- m5: transport failure carries the underlying error ---------------

    #[Test]
    public function transportFailureCapturesUnderlyingError(): void
    {
        // Allocate then abandon a port so the connection is refused fast.
        $deadServer = $this->startServer();
        $url = $deadServer->baseUrl() . '/ok';
        $deadServer->stop();
        array_pop($this->servers);

        $client = new StreamHttpClient(timeout: 2.0);

        try {
            $client->get($url);
            self::fail('Expected HttpRequestException for a refused connection.');
        } catch (HttpRequestException $e) {
            self::assertStringStartsWith('HTTP request failed:', $e->getMessage());
            // The message is enriched with the underlying error (not bare)...
            self::assertGreaterThan(strlen("HTTP request failed: GET {$url}"), strlen($e->getMessage()));
            // ...and the original error is chained for debuggability.
            self::assertInstanceOf(\ErrorException::class, $e->getPrevious());
        }
    }

    // ---- M1 headline: credentials are not re-sent across a redirect --------

    #[Test]
    public function doesNotFollowRedirectAndNeverContactsCrossHostTarget(): void
    {
        $target = $this->startServer();      // the redirect destination (a different host:port)
        $redirector = $this->startServer();  // returns 302 -> $target/secret

        $client = new StreamHttpClient();
        $response = $client->get(
            $redirector->baseUrl() . '/redirect?to=' . urlencode($target->baseUrl() . '/secret'),
            ['Authorization' => 'Bearer SUPER-SECRET'],
        );

        // The client returns the 3xx instead of following it.
        self::assertSame(302, $response->statusCode);
        self::assertStringNotContainsString('LEAKED-SECRET', $response->body);

        // The cross-host target was NEVER contacted → the Authorization header
        // was never re-sent to it.
        self::assertSame([], $target->requests(), 'redirect target must not be contacted');

        // The original host saw exactly one request, carrying the credential.
        $redirectorRequests = $redirector->requests();
        self::assertCount(1, $redirectorRequests);
        self::assertSame('Bearer SUPER-SECRET', $redirectorRequests[0]['authorization']);
    }

    // ---- m4: response body is complete or a typed transport failure --------

    #[Test]
    public function rejectsAnOverLimitBodyWithoutReturningAPartialSuccess(): void
    {
        $server = $this->startServer();
        $client = new StreamHttpClient(timeout: 5.0, maxResponseBytes: 8);

        try {
            $client->get($server->baseUrl() . '/big?n=100');
            self::fail('Expected HttpRequestException for an over-limit body.');
        } catch (HttpRequestException $exception) {
            $this->assertFailClosedBody($exception, 'exceeded', $server->baseUrl());
            self::assertSame('GET', $exception->method);
            self::assertStringNotContainsString(str_repeat('x', 8), $exception->getMessage());
        }
    }

    #[Test]
    public function acceptsACompleteBodyExactlyAtTheLimit(): void
    {
        $server = $this->startServer();
        $body = str_repeat('x', 32);

        $response = (new StreamHttpClient(timeout: 5.0, maxResponseBytes: 32))
            ->get($server->baseUrl() . '/big?n=32');

        self::assertSame(200, $response->statusCode);
        self::assertSame($body, $response->body);
    }

    #[Test]
    public function acceptsACompleteBodyBelowTheLimit(): void
    {
        $server = $this->startServer();

        $response = (new StreamHttpClient(timeout: 5.0, maxResponseBytes: 1024))
            ->get($server->baseUrl() . '/ok');

        self::assertSame(200, $response->statusCode);
        self::assertSame('OK', $response->body);
    }

    #[Test]
    public function rejectsAContentLengthMismatchWithoutAPartialSuccess(): void
    {
        $server = $this->startRaw(
            "HTTP/1.1 200 OK\r\nContent-Length: 100\r\nConnection: close\r\n\r\n" . str_repeat('x', 8),
        );
        $client = new StreamHttpClient(timeout: 5.0, maxResponseBytes: 1024);

        try {
            $client->get($server->baseUrl() . '/export');
            self::fail('Expected HttpRequestException for a truncated Content-Length body.');
        } catch (HttpRequestException $exception) {
            $this->assertFailClosedBody($exception, 'incomplete', $server->baseUrl());
            self::assertStringNotContainsString(str_repeat('x', 8), $exception->getMessage());
        }
    }

    #[Test]
    public function rejectsAChunkedBodyThatExceedsTheLimit(): void
    {
        $chunk = str_repeat('x', 32);
        $server = $this->startRaw(
            "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n"
            . sprintf("%x\r\n%s\r\n0\r\n\r\n", strlen($chunk), $chunk),
        );
        $client = new StreamHttpClient(timeout: 5.0, maxResponseBytes: 8);

        try {
            $client->get($server->baseUrl() . '/export');
            self::fail('Expected HttpRequestException for an over-limit chunked body.');
        } catch (HttpRequestException $exception) {
            $this->assertFailClosedBody($exception, 'exceeded', $server->baseUrl());
        }
    }

    #[Test]
    public function acceptsACompleteChunkedBodyAtTheLimit(): void
    {
        $chunk = str_repeat('z', 16);
        $server = $this->startRaw(
            "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n"
            . sprintf("%x\r\n%s\r\n0\r\n\r\n", strlen($chunk), $chunk),
        );

        $response = (new StreamHttpClient(timeout: 5.0, maxResponseBytes: 16))
            ->get($server->baseUrl() . '/export');

        self::assertSame(200, $response->statusCode);
        self::assertSame($chunk, $response->body);
    }

    #[Test]
    public function acceptsACompleteBodyWithNoContentLength(): void
    {
        $body = str_repeat('n', 16);
        $server = $this->startRaw(
            "HTTP/1.1 200 OK\r\nConnection: close\r\n\r\n" . $body,
        );

        $response = (new StreamHttpClient(timeout: 5.0, maxResponseBytes: 1024))
            ->get($server->baseUrl() . '/export');

        self::assertSame(200, $response->statusCode);
        self::assertSame($body, $response->body);
    }

    #[Test]
    public function rejectsAnOverLimitBodyWithNoContentLength(): void
    {
        $body = str_repeat('n', 32);
        $server = $this->startRaw(
            "HTTP/1.1 200 OK\r\nConnection: close\r\n\r\n" . $body,
        );
        $client = new StreamHttpClient(timeout: 5.0, maxResponseBytes: 8);

        try {
            $client->get($server->baseUrl() . '/export');
            self::fail('Expected HttpRequestException for an over-limit unknown-length body.');
        } catch (HttpRequestException $exception) {
            $this->assertFailClosedBody($exception, 'exceeded', $server->baseUrl());
        }
    }

    #[Test]
    public function rejectsAMidBodyTimeoutWithoutAPartialSuccess(): void
    {
        $prefix = str_repeat('t', 4);
        $suffix = str_repeat('u', 28);
        $payload = "HTTP/1.1 200 OK\r\nContent-Length: 32\r\nConnection: close\r\n\r\n" . $prefix . $suffix;
        $splitAt = strpos($payload, $prefix);
        self::assertNotFalse($splitAt);
        $server = $this->startRaw($payload, $splitAt + strlen($prefix), 2_500_000);
        $client = new StreamHttpClient(timeout: 1.0, maxResponseBytes: 1024);

        try {
            $client->get($server->baseUrl() . '/export');
            self::fail('Expected HttpRequestException for a mid-body timeout.');
        } catch (HttpRequestException $exception) {
            self::assertNull($exception->response);
            self::assertStringNotContainsString($prefix, $exception->getMessage());
            self::assertStringNotContainsString('Authorization', $exception->getMessage());
        }
    }

    #[Test]
    public function rejectsAnOverLimitErrorStatusWithoutAPartialSuccess(): void
    {
        $server = $this->startServer();
        $client = new StreamHttpClient(timeout: 5.0, maxResponseBytes: 8);

        try {
            $client->get($server->baseUrl() . '/error-big?n=100');
            self::fail('Expected HttpRequestException for an over-limit error body.');
        } catch (HttpRequestException $exception) {
            $this->assertFailClosedBody($exception, 'exceeded', $server->baseUrl());
        }
    }

    #[Test]
    public function stillReturnsAnOrdinaryCompleteNon2xxResponse(): void
    {
        $server = $this->startServer();
        $response = (new StreamHttpClient(timeout: 5.0, maxResponseBytes: 1024))
            ->get($server->baseUrl() . '/not-found');

        self::assertSame(404, $response->statusCode);
        self::assertSame('NOT FOUND', $response->body);
    }

    // ---- sanity: a normal request still works -----------------------------

    #[Test]
    public function performsANormalGet(): void
    {
        $server = $this->startServer();

        $response = (new StreamHttpClient())->get($server->baseUrl() . '/ok');

        self::assertSame(200, $response->statusCode);
        self::assertSame('OK', $response->body);
    }

    private function assertFailClosedBody(
        HttpRequestException $exception,
        string $messageNeedle,
        string $url,
    ): void {
        self::assertNull($exception->response);
        self::assertStringContainsString($messageNeedle, strtolower($exception->getMessage()));
        self::assertStringNotContainsString('Authorization', $exception->getMessage());
        self::assertStringNotContainsString($url, $exception->getMessage());
    }
}
