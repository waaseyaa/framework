<?php

declare(strict_types=1);

namespace Waaseyaa\HttpClient\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\HttpClient\HttpRequestException;
use Waaseyaa\HttpClient\StreamHttpClient;
use Waaseyaa\HttpClient\Tests\Support\LocalHttpServer;

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

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        $this->servers = [];
    }

    private function startServer(): LocalHttpServer
    {
        try {
            $server = new LocalHttpServer();
        } catch (\Throwable $e) {
            self::markTestSkipped('Local HTTP server unavailable: ' . $e->getMessage());
        }
        $this->servers[] = $server;

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

    // ---- m4: response body is capped --------------------------------------

    #[Test]
    public function capsResponseBodySize(): void
    {
        $server = $this->startServer();

        $client = new StreamHttpClient(timeout: 5.0, maxResponseBytes: 1024);
        $response = $client->get($server->baseUrl() . '/big?n=100000');

        self::assertLessThanOrEqual(1024, strlen($response->body));
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
}
