<?php

declare(strict_types=1);

namespace Waaseyaa\NorthCloud\Tests\Unit\Search;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\HttpClient\HttpClientInterface;
use Waaseyaa\HttpClient\HttpResponse;
use Waaseyaa\NorthCloud\Client\NorthCloudClient;
use Waaseyaa\NorthCloud\Search\NorthCloudSearchProvider;
use Waaseyaa\Search\SearchRequest;

/**
 * @covers \Waaseyaa\NorthCloud\Search\NorthCloudSearchProvider
 */
#[CoversClass(NorthCloudSearchProvider::class)]
final class NorthCloudSearchProviderTest extends TestCase
{
    #[Test]
    public function malformedHitTopicsAreIgnoredInsteadOfFatal(): void
    {
        $provider = new NorthCloudSearchProvider(
            client: new NorthCloudClient(
                baseUrl: 'https://nc.test',
                httpClient: FakeHttpClient::withResponse(new HttpResponse(200, (string) json_encode([
                    'hits' => [[
                        'id' => '1',
                        'title' => 'Hit',
                        'topics' => 'oops',
                    ]],
                    'total_hits' => 1,
                ]))),
            ),
            cacheTtl: 0,
        );

        $result = $provider->search(new SearchRequest(query: 'water'));

        $this->assertSame(1, $result->totalHits);
        $this->assertCount(1, $result->hits);
        $this->assertSame([], $result->hits[0]->topics);
    }

    #[Test]
    public function malformedFacetBucketsAreIgnoredInsteadOfFatal(): void
    {
        $provider = new NorthCloudSearchProvider(
            client: new NorthCloudClient(
                baseUrl: 'https://nc.test',
                httpClient: FakeHttpClient::withResponse(new HttpResponse(200, (string) json_encode([
                    'hits' => [],
                    'facets' => [
                        'topics' => 'oops',
                    ],
                    'total_hits' => 0,
                ]))),
            ),
            cacheTtl: 0,
        );

        $result = $provider->search(new SearchRequest(query: 'water'));

        $this->assertSame(0, $result->totalHits);
        $this->assertSame([], $result->facets);
    }
}

/**
 * Test double for HttpClientInterface, local to this file (see the identical
 * fake in Unit/Client/NorthCloudClientTest.php: northcloud has no wired
 * autoload-dev namespace for a shared tests/Support/ fake, so each namespace
 * that needs one declares its own).
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
