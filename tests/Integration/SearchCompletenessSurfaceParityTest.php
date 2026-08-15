<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\AI\Tools\ContentSearch\ContentSearchTool;
use Waaseyaa\Api\ContentSearch\ContentSearchRateLimiterInterface;
use Waaseyaa\Api\ContentSearch\SearchPackageContentSearchAdapter as ApiSearchAdapter;
use Waaseyaa\Api\Controller\ContentSearchController;
use Waaseyaa\Search\SearchProviderInterface;
use Waaseyaa\Search\SearchRequest;
use Waaseyaa\Search\SearchResult;

#[CoversClass(ContentSearchController::class)]
#[CoversClass(ContentSearchTool::class)]
final class SearchCompletenessSurfaceParityTest extends TestCase
{
    #[Test]
    public function http_and_mcp_preserve_the_same_incomplete_empty_result_for_the_exact_principal(): void
    {
        $provider = new class implements SearchProviderInterface {
            /** @var list<AuthorizationPrincipalInterface> */
            public array $principals = [];

            public function search(SearchRequest $request, AuthorizationPrincipalInterface $principal): SearchResult
            {
                $this->principals[] = $principal;

                return new SearchResult(
                    totalHits: 0,
                    totalPages: 0,
                    currentPage: $request->page,
                    pageSize: $request->pageSize,
                    hits: [],
                    facets: [],
                    isComplete: false,
                );
            }
        };
        $principal = new AuthorizationPrincipal(
            accountId: 7,
            authenticated: true,
            roles: ['member'],
            permissions: ['tool.content.search'],
            claimsGeneration: 'claims-v7',
            tenantId: 'tenant-a',
            communityId: 'community-a',
        );
        $limiter = self::createStub(ContentSearchRateLimiterInterface::class);
        $limiter->method('consume')->willReturn(true);
        $controller = new ContentSearchController(new ApiSearchAdapter($provider), $limiter);

        $httpResponse = $controller->search(
            Request::create('/api/content/search', 'GET', ['q' => 'needle']),
            $principal,
        );
        $http = json_decode((string) $httpResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $mcpResult = new ContentSearchTool(static fn(): object => $provider)->execute(
            ['query' => 'needle'],
            $principal,
        );

        self::assertSame(200, $httpResponse->getStatusCode());
        self::assertStringContainsString('no-store', (string) $httpResponse->headers->get('Cache-Control'));
        self::assertFalse($mcpResult->isError);
        self::assertSame([
            'total' => 0,
            'pages' => 0,
            'complete' => false,
            'hits' => [],
            'facets' => [],
        ], [
            'total' => $http['meta']['totalHits'],
            'pages' => $http['meta']['totalPages'],
            'complete' => $http['meta']['isComplete'],
            'hits' => $http['data'],
            'facets' => $http['meta']['facets'],
        ]);
        self::assertSame([
            'total' => 0,
            'pages' => 0,
            'complete' => false,
            'hits' => [],
            'facets' => [],
        ], [
            'total' => $mcpResult->structuredContent['total_hits'],
            'pages' => $mcpResult->structuredContent['total_pages'],
            'complete' => $mcpResult->structuredContent['is_complete'],
            'hits' => $mcpResult->structuredContent['hits'],
            'facets' => $mcpResult->structuredContent['facets'],
        ]);
        self::assertCount(2, $provider->principals);
        self::assertSame($principal, $provider->principals[0]);
        self::assertSame($principal, $provider->principals[1]);
    }
}
