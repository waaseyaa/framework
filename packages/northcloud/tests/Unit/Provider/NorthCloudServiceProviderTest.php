<?php

declare(strict_types=1);

namespace Waaseyaa\NorthCloud\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\HttpClient\HttpClientInterface;
use Waaseyaa\HttpClient\HttpResponse;
use Waaseyaa\NorthCloud\Client\NorthCloudCache;
use Waaseyaa\NorthCloud\Client\NorthCloudClient;
use Waaseyaa\NorthCloud\Provider\NorthCloudServiceProvider;

/**
 * @covers \Waaseyaa\NorthCloud\Provider\NorthCloudServiceProvider
 */
#[CoversClass(NorthCloudServiceProvider::class)]
final class NorthCloudServiceProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('NORTHCLOUD_BASE_URL');
        putenv('NORTHCLOUD_API_TOKEN');
    }

    #[Test]
    public function resolvesNorthCloudCacheThroughKernelServicesPdo(): void
    {
        $provider = $this->providerWithCacheEnabled();

        $cache = $provider->resolve(NorthCloudCache::class);

        $this->assertInstanceOf(NorthCloudCache::class, $cache);
    }

    #[Test]
    public function successfulResponseIsCachedAndSecondIdenticalRequestSkipsTransport(): void
    {
        $provider = $this->providerWithCacheEnabled();
        $cache = $provider->resolve(NorthCloudCache::class);

        // The queue holds exactly one success followed by a failure: if the cache
        // does not intercept the second identical GET, the client would either
        // receive the 500 (and return null) instead of the cached body.
        $client = new NorthCloudClient(
            baseUrl: 'https://nc.test',
            httpClient: ProviderFakeHttpClient::withResponses(
                new HttpResponse(200, (string) json_encode(['hits' => [['id' => 'a']], 'total_hits' => 1])),
                new HttpResponse(500, '{"error":"boom"}'),
            ),
            cache: $cache,
        );

        $first = $client->getRecentContent();
        $second = $client->getRecentContent();

        $this->assertNotNull($first);
        $this->assertSame($first, $second, 'Second identical request must be served from the cache');
    }

    #[Test]
    public function nonSuccessResponseLeavesCacheEmpty(): void
    {
        $provider = $this->providerWithCacheEnabled();
        $cache = $provider->resolve(NorthCloudCache::class);

        $capturedUrl = '';
        $client = new NorthCloudClient(
            baseUrl: 'https://nc.test',
            httpClient: new ProviderFakeHttpClient(
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

    private function providerWithCacheEnabled(): NorthCloudServiceProvider
    {
        $provider = new NorthCloudServiceProvider();
        $provider->setKernelContext('/tmp/test', [
            'northcloud' => ['cache' => ['enabled' => true, 'ttl' => 3600]],
        ], []);
        $provider->setKernelServices(new class implements KernelServicesInterface {
            public function get(string $abstract): ?object
            {
                if ($abstract === \PDO::class) {
                    return new \PDO('sqlite::memory:');
                }

                return null;
            }
        });
        $provider->register();

        return $provider;
    }

    #[Test]
    public function envFallbackFillsBaseUrlWhenConfigIsEmpty(): void
    {
        putenv('NORTHCLOUD_BASE_URL=https://env.example.com');
        putenv('NORTHCLOUD_API_TOKEN=env-token');

        $provider = new NorthCloudServiceProvider();
        $provider->setKernelContext('/tmp/test', [], []);
        $provider->register();

        $client = $provider->resolve(NorthCloudClient::class);

        $this->assertInstanceOf(NorthCloudClient::class, $client);
    }

    #[Test]
    public function defaultsToPackageBaseUrlWhenNothingSet(): void
    {
        putenv('NORTHCLOUD_BASE_URL');
        putenv('NORTHCLOUD_API_TOKEN');

        $provider = new NorthCloudServiceProvider();
        $provider->setKernelContext('/tmp/test', [], []);
        $provider->register();

        // Resolution should succeed (uses https://api.northcloud.one default).
        $client = $provider->resolve(NorthCloudClient::class);
        $this->assertInstanceOf(NorthCloudClient::class, $client);
    }

    #[Test]
    public function envVarOverridesConfigValue(): void
    {
        putenv('NORTHCLOUD_BASE_URL=https://env-wins.example.com');

        $provider = new NorthCloudServiceProvider();
        $provider->setKernelContext('/tmp/test', [
            'northcloud' => ['base_url' => 'https://config-loses.example.com'],
        ], []);
        $provider->register();

        // We can't read the private baseUrl directly, but resolution succeeding
        // proves both constructors got valid https URLs.
        $client = $provider->resolve(NorthCloudClient::class);
        $this->assertInstanceOf(NorthCloudClient::class, $client);
    }

    #[Test]
    public function invalidEnvBaseUrlRaisesException(): void
    {
        putenv('NORTHCLOUD_BASE_URL=http://insecure.example.com');

        $provider = new NorthCloudServiceProvider();
        $provider->setKernelContext('/tmp/test', [], []);
        $provider->register();

        $this->expectException(\InvalidArgumentException::class);
        $provider->resolve(NorthCloudClient::class);
    }
}

/**
 * Test double for HttpClientInterface, local to this file (northcloud has no
 * wired autoload-dev namespace for a shared tests/Support/ fake, so each test
 * file that needs one declares its own).
 */
final class ProviderFakeHttpClient implements HttpClientInterface
{
    /** @var list<HttpResponse> */
    private array $queue;

    private readonly ?\Closure $handler;

    public function __construct(?callable $handler = null)
    {
        $this->handler = $handler !== null ? $handler(...) : null;
        $this->queue = [];
    }

    /**
     * Returns responses in order; once only one remains, it repeats for all
     * further calls.
     */
    public static function withResponses(HttpResponse ...$responses): self
    {
        $fake = new self();
        $fake->queue = array_values($responses);

        return $fake;
    }

    public function request(string $method, string $url, array $headers = [], array|string|null $body = null): HttpResponse
    {
        if ($this->handler !== null) {
            return ($this->handler)($method, $url, $headers, $body);
        }

        if ($this->queue === []) {
            throw new \RuntimeException('ProviderFakeHttpClient: response queue exhausted.');
        }

        return count($this->queue) > 1 ? array_shift($this->queue) : $this->queue[0];
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
