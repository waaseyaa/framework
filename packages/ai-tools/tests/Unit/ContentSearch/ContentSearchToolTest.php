<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Unit\ContentSearch;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\AI\Tools\ContentSearch\ContentSearchTool;
use Waaseyaa\AI\Tools\ContentSearch\SearchPackageContentSearchAdapter;
use Waaseyaa\AI\Tools\Schema\ToolInputSchemaValidator;
use Waaseyaa\Search\FacetBucket;
use Waaseyaa\Search\SearchFacet;
use Waaseyaa\Search\SearchHit;
use Waaseyaa\Search\SearchProviderInterface;
use Waaseyaa\Search\SearchRequest;
use Waaseyaa\Search\SearchResult;

#[CoversClass(ContentSearchTool::class)]
#[CoversClass(SearchPackageContentSearchAdapter::class)]
final class ContentSearchToolTest extends TestCase
{
    #[Test]
    public function it_returns_a_closed_rich_result_and_passes_the_principal_verbatim(): void
    {
        $account = $this->account(['tool.content.search']);
        $seenPrincipal = null;
        $provider = new class($seenPrincipal) implements SearchProviderInterface {
            public ?AuthorizationPrincipalInterface $seenPrincipal = null;

            public function __construct(?AuthorizationPrincipalInterface &$seenPrincipal)
            {
                $this->seenPrincipal = &$seenPrincipal;
            }

            public function search(SearchRequest $request, AuthorizationPrincipalInterface $principal): SearchResult
            {
                $this->seenPrincipal = $principal;
                TestCase::assertSame('water', $request->query);
                TestCase::assertSame(['culture'], $request->filters->topics);

                return new SearchResult(1, 1, 1, 20, [
                    new SearchHit('page:7', 'Water', '/water', 'site', '2026-08-04T00:00:00Z', 92, 'page', ['culture'], 4.25, '/water.jpg', 'Clean water'),
                ], [new SearchFacet('topics', [new FacetBucket('culture', 1)])], isComplete: false);
            }
        };
        $tool = new ContentSearchTool(static fn(): object => $provider);

        $result = $tool->execute(['query' => 'water', 'topics' => ['culture']], $account);

        self::assertFalse($result->isError, json_encode($result->content, JSON_THROW_ON_ERROR));
        self::assertSame($account, $seenPrincipal);
        self::assertSame(1, $result->structuredContent['total_hits']);
        self::assertFalse($result->structuredContent['is_complete']);
        self::assertSame('/water', $result->structuredContent['hits'][0]['url']);
        self::assertSame('Clean water', $result->structuredContent['hits'][0]['highlight']);
        self::assertSame([['key' => 'culture', 'count' => 1]], $result->structuredContent['facets'][0]['buckets']);
        self::assertFalse(ContentSearchTool::outputSchema()['additionalProperties']);
        self::assertSame([], ToolInputSchemaValidator::validate(ContentSearchTool::outputSchema(), $result->structuredContent));
    }

    #[Test]
    public function it_requires_its_distinct_capability_without_resolving_search(): void
    {
        $resolved = false;
        $tool = new ContentSearchTool(static function () use (&$resolved): object {
            $resolved = true;
            throw new \LogicException('must not resolve');
        });

        $result = $tool->execute(['query' => 'water'], $this->account([]));

        self::assertTrue($result->isError);
        self::assertSame('forbidden', $result->summary);
        self::assertFalse($resolved);
    }

    #[Test]
    public function audit_arguments_never_retain_query_content(): void
    {
        $tool = new ContentSearchTool(static fn(): object => throw new \LogicException('unused'));

        $safe = $tool->argumentsForAudit([
            'query' => 'private language revitalization plan',
            'page' => 2,
            'topics' => ['language'],
        ]);

        self::assertSame(['redacted' => true, 'length' => 36], $safe['query']);
        self::assertSame(['redacted' => true, 'count' => 1], $safe['topics']);
        self::assertSame(2, $safe['page']);
        self::assertStringNotContainsString('private', json_encode($safe, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('language', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function invalid_input_is_refused_before_the_optional_provider_is_resolved(): void
    {
        $resolved = false;
        $tool = new ContentSearchTool(static function () use (&$resolved): object {
            $resolved = true;
            throw new \LogicException('must not resolve');
        });

        foreach ([
            [],
            ['query' => "unsafe\nquery"],
            ['query' => 'ok', 'page_size' => 101],
            ['query' => 'ok', 'page' => 51, 'page_size' => 20],
        ] as $arguments) {
            $result = $tool->execute($arguments, $this->account(['tool.content.search']));
            self::assertTrue($result->isError);
            self::assertSame('validation_failed', $result->summary);
        }
        self::assertFalse($resolved);
    }

    #[Test]
    public function a_missing_or_broken_search_binding_returns_a_sanitized_error(): void
    {
        $tool = new ContentSearchTool(static fn(): object => throw new \RuntimeException('sqlite:////secret/path?token=abc'));

        $result = $tool->execute(['query' => 'water'], $this->account(['tool.content.search']));

        self::assertTrue($result->isError);
        $body = json_encode($result->content, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('secret', $body);
        self::assertStringNotContainsString('sqlite', $body);
        self::assertStringContainsString('TOOL_UNAVAILABLE', $body);
        self::assertStringContainsString('correlation_id', $body);
    }

    #[Test]
    public function malformed_optional_provider_output_is_refused_at_the_adapter_boundary(): void
    {
        $provider = new class implements SearchProviderInterface {
            public function search(SearchRequest $request, AuthorizationPrincipalInterface $principal): SearchResult
            {
                return new SearchResult(1, 1, 1, 20, [
                    new SearchHit('page:7', 'Water', '/water', 'site', '2026-08-04T00:00:00Z', 92, 'page', [], NAN),
                ]);
            }
        };
        $tool = new ContentSearchTool(static fn(): object => $provider);

        $result = $tool->execute(['query' => 'water'], $this->account(['tool.content.search']));

        self::assertTrue($result->isError);
        self::assertStringStartsWith('INTERNAL_ERROR (correlation_id=', $result->summary);
        self::assertStringContainsString('correlation_id', json_encode($result->content, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('score', json_encode($result->content, JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $permissions */
    private function account(array $permissions): AuthorizationPrincipalInterface
    {
        return new class($permissions) implements AuthorizationPrincipalInterface {
            /** @param list<string> $permissions */
            public function __construct(private readonly array $permissions) {}

            public function id(): int|string { return 7; }
            public function hasPermission(string $permission): bool { return in_array($permission, $this->permissions, true); }
            public function getRoles(): array { return []; }
            public function isAuthenticated(): bool { return true; }
            public function claimsGeneration(): string { return 'test-v1'; }
            public function tenantId(): ?string { return null; }
            public function communityId(): ?string { return null; }
        };
    }
}
