<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Api\ContentSearch\ContentSearchPage;
use Waaseyaa\Api\ContentSearch\ContentSearchQuery;
use Waaseyaa\Api\ContentSearch\ContentSearchRateLimiterInterface;
use Waaseyaa\Api\ContentSearch\ContentSearchReadModelInterface;
use Waaseyaa\Api\Controller\ContentSearchController;

#[CoversClass(ContentSearchController::class)]
final class ContentSearchControllerTest extends TestCase
{
    #[Test]
    public function it_projects_only_the_closed_safe_search_contract_for_the_exact_principal(): void
    {
        $principal = $this->principal(42, true);
        $provider = $this->createMock(ContentSearchReadModelInterface::class);
        $provider->expects($this->once())
            ->method('search')
            ->with(
                $this->callback(static fn(ContentSearchQuery $request): bool => $request->query === 'community'
                    && $request->page === 2
                    && $request->pageSize === 10
                    && $request->topics === ['events']
                    && $request->contentType === 'article'
                    && $request->sourceNames === ['site']
                    && $request->minQuality === 50),
                $this->identicalTo($principal),
            )
            ->willReturn(new ContentSearchPage(
                totalHits: 1,
                totalPages: 1,
                currentPage: 2,
                pageSize: 10,
                hits: [[
                    'id' => 'node:1',
                    'title' => 'Community update',
                    'url' => '/updates/community',
                    'highlight' => 'A safe community summary.',
                    'score' => 4.5,
                    'source' => 'site',
                    'contentType' => 'article',
                    'qualityScore' => 90,
                    'crawledAt' => '2026-08-05T00:00:00Z',
                    'topics' => ['events'],
                    'image' => '/media/community.jpg',
                ]],
                facets: [['name' => 'topics', 'buckets' => [['key' => 'events', 'count' => 1]]]],
            ));
        $limiter = $this->allowingLimiter($keys);
        $controller = new ContentSearchController($provider, $limiter);

        $response = $controller->search(Request::create('/api/content/search', 'GET', [
            'q' => 'community',
            'page' => '2',
            'page_size' => '10',
            'topic' => ['events'],
            'content_type' => 'article',
            'source' => ['site'],
            'min_quality' => '50',
        ]), $principal);
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame(['api-content-search:global', $keys[1]], $keys);
        self::assertStringStartsWith('api-content-search:principal:', $keys[1]);
        self::assertSame(['jsonapi', 'data', 'meta'], array_keys($payload));
        self::assertSame(['type', 'id', 'attributes'], array_keys($payload['data'][0]));
        self::assertSame([
            'title', 'url', 'highlight', 'score', 'source', 'contentType',
            'qualityScore', 'crawledAt', 'topics', 'image',
        ], array_keys($payload['data'][0]['attributes']));
        self::assertSame('Community update', $payload['data'][0]['attributes']['title']);
        self::assertSame(1, $payload['meta']['totalHits']);
        self::assertSame('events', $payload['meta']['facets'][0]['buckets'][0]['key']);
        self::assertArrayNotHasKey('body', $payload['data'][0]['attributes']);
        self::assertArrayNotHasKey('tookMs', $payload['meta']);
    }

    #[Test]
    public function anonymous_keys_are_fixed_and_ignore_forwarding_headers(): void
    {
        $provider = $this->emptyProvider();
        $limiter = $this->allowingLimiter($keys);
        $request = Request::create('/api/content/search?q=public');
        $request->headers->set('X-Forwarded-For', '2001:db8::1234');

        (new ContentSearchController($provider, $limiter))->search($request, $this->principal(0, false));

        self::assertSame(['api-content-search:global', 'api-content-search:anonymous'], $keys);
    }

    #[Test]
    public function global_or_identity_exhaustion_refuses_before_search(): void
    {
        foreach ([[false], [true, false]] as $decisions) {
            $provider = $this->createMock(ContentSearchReadModelInterface::class);
            $provider->expects($this->never())->method('search');
            $limiter = $this->createMock(ContentSearchRateLimiterInterface::class);
            $limiter->method('consume')->willReturnOnConsecutiveCalls(...$decisions);

            $response = (new ContentSearchController($provider, $limiter))->search(
                Request::create('/api/content/search?q=community'),
                $this->principal(0, false),
            );
            $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

            self::assertSame(429, $response->getStatusCode());
            self::assertSame('60', $response->headers->get('Retry-After'));
            self::assertSame('429', $payload['errors'][0]['status']);
        }
    }

    #[Test]
    public function limiter_failure_is_a_sanitized_503(): void
    {
        $provider = $this->createMock(ContentSearchReadModelInterface::class);
        $provider->expects($this->never())->method('search');
        $limiter = $this->createStub(ContentSearchRateLimiterInterface::class);
        $limiter->method('consume')->willThrowException(new \RuntimeException('sqlite path and secret details'));

        $response = (new ContentSearchController($provider, $limiter))->search(
            Request::create('/api/content/search?q=community'),
            $this->principal(0, false),
        );

        self::assertSame(503, $response->getStatusCode());
        self::assertStringNotContainsString('sqlite', (string) $response->getContent());
        self::assertStringNotContainsString('secret', (string) $response->getContent());
    }

    #[Test]
    public function malformed_or_unknown_input_is_a_json_api_400_before_rate_limiting(): void
    {
        foreach ([
            ['q' => ''],
            ['q' => 'query', 'page' => '201'],
            ['q' => 'query', 'page_size' => '101'],
            ['q' => 'query', 'unknown' => 'value'],
            ['q' => "invalid \xC3\x28"],
        ] as $query) {
            $provider = $this->createMock(ContentSearchReadModelInterface::class);
            $provider->expects($this->never())->method('search');
            $limiter = $this->createMock(ContentSearchRateLimiterInterface::class);
            $limiter->expects($this->never())->method('consume');

            $response = (new ContentSearchController($provider, $limiter))->search(
                Request::create('/api/content/search', 'GET', $query),
                $this->principal(0, false),
            );
            $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

            self::assertSame(400, $response->getStatusCode());
            self::assertSame(['jsonapi', 'errors'], array_keys($payload));
            self::assertSame('400', $payload['errors'][0]['status']);
        }
    }

    #[Test]
    public function head_returns_the_same_status_and_headers_without_a_body(): void
    {
        $provider = $this->emptyProvider();
        $limiter = $this->allowingLimiter($keys);

        $response = (new ContentSearchController($provider, $limiter))->search(
            Request::create('/api/content/search?q=community', 'HEAD'),
            $this->principal(0, false),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
    }

    private function principal(int $id, bool $authenticated): AuthorizationPrincipalInterface
    {
        return new AuthorizationPrincipal(
            accountId: $id,
            authenticated: $authenticated,
            roles: $authenticated ? ['authenticated'] : [],
            permissions: [],
            claimsGeneration: $authenticated ? 'claims-1' : 'anonymous',
        );
    }

    /** @param-out list<string> $keys */
    private function allowingLimiter(?array &$keys): ContentSearchRateLimiterInterface
    {
        $keys = [];
        $limiter = $this->createStub(ContentSearchRateLimiterInterface::class);
        $limiter->method('consume')->willReturnCallback(
            static function (string $key, int $max, int $window) use (&$keys): bool {
                $keys[] = $key;
                self::assertGreaterThan(0, $max);
                self::assertSame(60, $window);

                return true;
            },
        );

        return $limiter;
    }

    private function emptyProvider(): ContentSearchReadModelInterface
    {
        $provider = $this->createStub(ContentSearchReadModelInterface::class);
        $provider->method('search')->willReturn(new ContentSearchPage(0, 0, 1, 20, [], []));

        return $provider;
    }
}
