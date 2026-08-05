<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Api\ContentSearch\ContentSearchRateLimiterInterface;
use Waaseyaa\Api\ContentSearch\SearchPackageContentSearchAdapter;
use Waaseyaa\Api\Controller\ContentSearchController;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Search\Fts5\Fts5SearchIndexer;
use Waaseyaa\Search\Fts5\Fts5SearchProvider;
use Waaseyaa\Search\SearchCandidateProjection;
use Waaseyaa\Search\SearchCandidateReference;
use Waaseyaa\Search\SearchCandidateResolverInterface;
use Waaseyaa\Search\SearchIndexableInterface;

#[CoversClass(ContentSearchController::class)]
final class ContentSearchAccessIntegrationTest extends TestCase
{
    #[Test]
    public function anonymous_and_authenticated_responses_reflect_only_their_principal_safe_projections(): void
    {
        $database = DBALDatabase::createSqlite();
        $indexer = new Fts5SearchIndexer($database);
        $this->index($indexer, 'node:1', 'Community raw private label', 'publicterm raw secretterm');
        $this->index($indexer, 'node:2', 'Community members raw label', 'memberterm raw body');

        $resolver = new class implements SearchCandidateResolverInterface {
            public function resolve(SearchCandidateReference $reference, AuthorizationPrincipalInterface $principal): ?SearchCandidateProjection
            {
                return match ($reference->documentId) {
                    'node:1' => new SearchCandidateProjection(
                        id: 'node:1',
                        entityType: 'node',
                        title: 'Community public page',
                        body: 'publicterm safe summary',
                        url: '/community',
                        sourceName: 'site',
                        contentType: 'page',
                        topics: ['community'],
                    ),
                    'node:2' => $principal->isAuthenticated() ? new SearchCandidateProjection(
                        id: 'node:2',
                        entityType: 'node',
                        title: 'Community member page',
                        body: 'memberterm safe summary',
                        url: '/members/community',
                        sourceName: 'site',
                        contentType: 'page',
                        topics: ['members'],
                    ) : null,
                    default => null,
                };
            }
        };
        $limiter = $this->createStub(ContentSearchRateLimiterInterface::class);
        $limiter->method('consume')->willReturn(true);
        $controller = new ContentSearchController(
            new SearchPackageContentSearchAdapter(new Fts5SearchProvider($database, $indexer, $resolver)),
            $limiter,
        );

        $anonymous = $this->payload($controller, 'Community', new AuthorizationPrincipal(0, false, [], [], 'anonymous'));
        $authenticated = $this->payload($controller, 'Community', new AuthorizationPrincipal(7, true, ['member'], [], 'claims-7'));
        $rawOnly = $this->payload($controller, 'secretterm', new AuthorizationPrincipal(0, false, [], [], 'anonymous'));

        self::assertSame(1, $anonymous['meta']['totalHits']);
        self::assertSame(['node:1'], array_column($anonymous['data'], 'id'));
        self::assertSame(2, $authenticated['meta']['totalHits']);
        self::assertSame(['node:1', 'node:2'], array_column($authenticated['data'], 'id'));
        self::assertSame(0, $rawOnly['meta']['totalHits']);
        self::assertSame([], $rawOnly['data']);
        self::assertStringNotContainsString('private label', json_encode($anonymous, JSON_THROW_ON_ERROR));
        self::assertSame('community', $anonymous['meta']['facets'][2]['buckets'][0]['key']);
    }

    /** @return array<string, mixed> */
    private function payload(ContentSearchController $controller, string $query, AuthorizationPrincipalInterface $principal): array
    {
        $response = $controller->search(Request::create('/api/content/search', 'GET', ['q' => $query]), $principal);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }

    private function index(Fts5SearchIndexer $indexer, string $id, string $title, string $body): void
    {
        $indexer->index(new class ($id, $title, $body) implements SearchIndexableInterface {
            public function __construct(
                private readonly string $id,
                private readonly string $title,
                private readonly string $body,
            ) {}

            public function getSearchDocumentId(): string { return $this->id; }
            public function toSearchDocument(): array { return ['title' => $this->title, 'body' => $this->body]; }
            public function toSearchMetadata(): array
            {
                return [
                    'entity_type' => 'node',
                    'content_type' => 'raw-private',
                    'source_name' => 'raw-private',
                    'topics' => ['raw-private'],
                ];
            }
        });
    }
}
