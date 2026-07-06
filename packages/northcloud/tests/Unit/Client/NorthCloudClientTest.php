<?php

declare(strict_types=1);

namespace Waaseyaa\NorthCloud\Tests\Unit\Client;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\HttpClient\HttpClientInterface;
use Waaseyaa\HttpClient\HttpResponse;
use Waaseyaa\NorthCloud\Client\NorthCloudCache;
use Waaseyaa\NorthCloud\Client\NorthCloudClient;

/**
 * @covers \Waaseyaa\NorthCloud\Client\NorthCloudClient
 */
#[CoversClass(NorthCloudClient::class)]
final class NorthCloudClientTest extends TestCase
{
    #[Test]
    public function getRecentContentReturnsNullOnNon2xxStatus(): void
    {
        $client = new NorthCloudClient(
            baseUrl: 'https://nc.test',
            httpClient: FakeHttpClient::withResponse(new HttpResponse(500, '{"error":"boom"}')),
        );

        $this->assertNull($client->getRecentContent());
    }

    #[Test]
    public function getRecentContentReturnsHitsOn2xxStatus(): void
    {
        $client = new NorthCloudClient(
            baseUrl: 'https://nc.test',
            httpClient: FakeHttpClient::withResponse(new HttpResponse(200, (string) json_encode([
                'hits' => [['title' => 'Hit 1']],
                'total_hits' => 1,
            ]))),
        );

        $result = $client->getRecentContent();

        $this->assertNotNull($result);
        $this->assertCount(1, $result['hits']);
        $this->assertSame(1, $result['total_hits']);
    }

    #[Test]
    public function authenticatedRequestReturnsNullOnNon2xxStatus(): void
    {
        $client = new NorthCloudClient(
            baseUrl: 'https://nc.test',
            httpClient: FakeHttpClient::withResponse(new HttpResponse(403, '{"error":"forbidden"}')),
            apiToken: 'secret',
        );

        $this->assertNull($client->linkSources(dryRun: false));
    }

    #[Test]
    public function nonSuccessResponseIsNotCached(): void
    {
        $cache = new NorthCloudCache(new \PDO('sqlite::memory:'));
        $capturedUrl = '';
        $client = new NorthCloudClient(
            baseUrl: 'https://nc.test',
            httpClient: new FakeHttpClient(
                static function (string $method, string $url) use (&$capturedUrl): HttpResponse {
                    $capturedUrl = $url;
                    return new HttpResponse(500, '{"error":"boom"}');
                },
            ),
            cache: $cache,
        );

        $this->assertNull($client->getRecentContent());
        $this->assertNotSame('', $capturedUrl, 'Fake HTTP client should have been called');
        $this->assertNull($cache->get($capturedUrl), 'A non-2xx response must never be written to the cache');
    }

    #[Test]
    public function getRecentContentReturnsHitsAndTotal(): void
    {
        $client = new NorthCloudClient(
            baseUrl: 'https://nc.test',
            httpClient: FakeHttpClient::withResponse(new HttpResponse(200, (string) json_encode([
                'hits' => [['title' => 'Hit 1'], ['title' => 'Hit 2']],
                'total_hits' => 2,
            ]))),
        );

        $result = $client->getRecentContent();

        $this->assertNotNull($result);
        $this->assertCount(2, $result['hits']);
        $this->assertSame(2, $result['total_hits']);
    }

    #[Test]
    public function getRecentContentReturnsNullOnMalformedResponse(): void
    {
        $client = new NorthCloudClient(
            baseUrl: 'https://nc.test',
            httpClient: FakeHttpClient::withResponse(new HttpResponse(200, '{"not_hits": []}')),
        );

        $this->assertNull($client->getRecentContent());
    }

    #[Test]
    public function getRecentContentBuildsUrlWithTopicsAndSince(): void
    {
        $capturedUrl = '';
        $client = new NorthCloudClient(
            baseUrl: 'https://nc.test',
            httpClient: new FakeHttpClient(
                static function (string $method, string $url) use (&$capturedUrl): HttpResponse {
                    $capturedUrl = $url;
                    return new HttpResponse(200, (string) json_encode(['hits' => [], 'total_hits' => 0]));
                },
            ),
        );

        $client->getRecentContent(limit: 10, since: '2026-01-01', topics: ['indigenous', 'governance']);

        $this->assertStringContainsString('size=10', $capturedUrl);
        $this->assertStringContainsString('topics[]=indigenous', $capturedUrl);
        $this->assertStringContainsString('topics[]=governance', $capturedUrl);
        $this->assertStringContainsString('from_date=2026-01-01', $capturedUrl);
    }

    #[Test]
    public function getPeopleReturnsPeopleArray(): void
    {
        $client = new NorthCloudClient(
            baseUrl: 'https://nc.test',
            httpClient: FakeHttpClient::withResponse(new HttpResponse(200, (string) json_encode([
                'people' => [
                    ['id' => '1', 'name' => 'Chief Test', 'role' => 'chief', 'verified' => true],
                ],
            ]))),
        );

        $people = $client->getPeople('nc-community-123');

        $this->assertNotNull($people);
        $this->assertCount(1, $people);
        $this->assertSame('Chief Test', $people[0]['name']);
    }

    #[Test]
    public function searchDictionaryReturnsEntriesAndAttribution(): void
    {
        $client = new NorthCloudClient(
            baseUrl: 'https://nc.test',
            httpClient: FakeHttpClient::withResponse(new HttpResponse(200, (string) json_encode([
                'entries' => [['word' => 'aanii']],
                'total' => 1,
            ]))),
        );

        $result = $client->searchDictionary('aanii');

        $this->assertNotNull($result);
        $this->assertCount(1, $result['entries']);
        $this->assertSame(1, $result['total']);
        $this->assertSame(NorthCloudClient::DICTIONARY_ATTRIBUTION, $result['attribution']);
    }

    #[Test]
    public function unauthenticatedWriteCallReturnsNullWhenTokenMissing(): void
    {
        $client = new NorthCloudClient(baseUrl: 'https://nc.test');

        // No custom httpClient, no api token: forces the real authenticated path, which bails before any transport call.
        $this->assertNull($client->linkSources());
    }

    #[Test]
    public function authenticatedCallUsesInjectedHttpClient(): void
    {
        $capturedMethod = '';
        $client = new NorthCloudClient(
            baseUrl: 'https://nc.test',
            httpClient: new FakeHttpClient(
                static function (string $method) use (&$capturedMethod): HttpResponse {
                    $capturedMethod = $method;
                    return new HttpResponse(200, (string) json_encode(['ok' => true]));
                },
            ),
            apiToken: 'secret',
        );

        $result = $client->linkSources(dryRun: false);

        $this->assertSame(['ok' => true], $result);
        $this->assertSame('POST', $capturedMethod);
    }

    #[Test]
    public function authenticatedCallPassesBearerHeaderToInjectedClient(): void
    {
        $capturedHeaders = [];
        $client = new NorthCloudClient(
            baseUrl: 'https://nc.test',
            httpClient: new FakeHttpClient(
                static function (string $method, string $url, array $headers) use (&$capturedHeaders): HttpResponse {
                    $capturedHeaders = $headers;
                    return new HttpResponse(200, (string) json_encode(['ok' => true]));
                },
            ),
            apiToken: 'secret-token',
        );

        $client->linkSources(dryRun: false);

        $this->assertSame('Bearer secret-token', $capturedHeaders['Authorization'] ?? null);
        $this->assertSame('application/json', $capturedHeaders['Content-Type'] ?? null);
    }

    #[Test]
    public function authenticatedCallReturnsNullWhenTokenEmptyEvenWithInjectedClient(): void
    {
        $called = false;
        $client = new NorthCloudClient(
            baseUrl: 'https://nc.test',
            httpClient: new FakeHttpClient(
                static function () use (&$called): HttpResponse {
                    $called = true;
                    return new HttpResponse(200, '{"ok": true}');
                },
            ),
        );

        $this->assertNull($client->linkSources());
        $this->assertFalse($called, 'Injected client should not be called when token is empty');
    }

    #[Test]
    public function constructorRejectsHttpBaseUrlByDefault(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new NorthCloudClient(baseUrl: 'http://nc.test');
    }

    #[Test]
    public function constructorAcceptsHttpsBaseUrl(): void
    {
        $client = new NorthCloudClient(baseUrl: 'https://nc.test');
        $this->assertInstanceOf(NorthCloudClient::class, $client);
    }

    #[Test]
    public function constructorAcceptsHttpBaseUrlWhenAllowInsecure(): void
    {
        $client = new NorthCloudClient(baseUrl: 'http://nc.test', allowInsecure: true);
        $this->assertInstanceOf(NorthCloudClient::class, $client);
    }

    #[Test]
    public function searchMethodBuildsArrayParamsAndReturnsDecodedResponse(): void
    {
        $capturedUrl = '';
        $client = new NorthCloudClient(
            baseUrl: 'https://nc.test',
            httpClient: new FakeHttpClient(
                static function (string $method, string $url) use (&$capturedUrl): HttpResponse {
                    $capturedUrl = $url;
                    return new HttpResponse(200, (string) json_encode(['hits' => [['id' => 'x']], 'total_hits' => 1]));
                },
            ),
        );

        $result = $client->search([
            'q' => 'water',
            'page' => 1,
            'topics' => ['indigenous', 'governance'],
        ]);

        $this->assertNotNull($result);
        $this->assertSame(1, $result['total_hits']);
        $this->assertStringContainsString('q=water', $capturedUrl);
        $this->assertStringContainsString('page=1', $capturedUrl);
        $this->assertStringContainsString('topics%5B%5D=indigenous', $capturedUrl);
        $this->assertStringContainsString('topics%5B%5D=governance', $capturedUrl);
    }
}

/**
 * Test double for HttpClientInterface, local to this file (northcloud has no
 * wired autoload-dev namespace for a shared tests/Support/ fake, so each test
 * file that needs one declares its own, matching the FakeMapper/WorkerFakeMapper
 * convention already used under tests/Unit/Sync).
 */
final class FakeHttpClient implements HttpClientInterface
{
    /** @var \Closure(string, string, array<string, string>, array<string, mixed>|string|null): HttpResponse */
    private readonly \Closure $handler;

    public function __construct(callable $handler)
    {
        $this->handler = $handler(...);
    }

    public static function withResponse(HttpResponse $response): self
    {
        return new self(static fn(): HttpResponse => $response);
    }

    public function request(string $method, string $url, array $headers = [], array|string|null $body = null): HttpResponse
    {
        return ($this->handler)($method, $url, $headers, $body);
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        return $this->request('GET', $url, $headers);
    }

    public function post(string $url, array $headers = [], array|string|null $body = null): HttpResponse
    {
        return $this->request('POST', $url, $headers, $body);
    }
}
