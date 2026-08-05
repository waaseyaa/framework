<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Unit\Twig;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Search\SearchProviderInterface;
use Waaseyaa\Search\SearchRequest;
use Waaseyaa\Search\SearchResult;
use Waaseyaa\Search\Tests\Support\SearchTestPrincipal;
use Waaseyaa\Search\Twig\SearchTwigExtension;

/**
 * @covers \Waaseyaa\Search\Twig\SearchTwigExtension
 */
#[CoversClass(SearchTwigExtension::class)]
final class SearchTwigExtensionTest extends TestCase
{
    #[Test]
    public function it_registers_two_twig_functions(): void
    {
        $provider = $this->createStub(SearchProviderInterface::class);
        $ext = new SearchTwigExtension($provider);
        $functions = $ext->getFunctions();

        $names = array_map(fn($f) => $f->getName(), $functions);
        $this->assertContains('search', $names);
        $this->assertContains('query_param', $names);
    }

    #[Test]
    public function search_returns_empty_for_blank_query(): void
    {
        $provider = $this->createStub(SearchProviderInterface::class);
        $provider->method('search')->willReturn(SearchResult::empty());

        $ext = new SearchTwigExtension($provider);
        $result = $ext->search(SearchTestPrincipal::create(), '');

        $this->assertSame(0, $result->totalHits);
    }

    #[Test]
    public function malformed_user_filters_fail_closed_without_calling_the_provider(): void
    {
        $provider = $this->createMock(SearchProviderInterface::class);
        $provider->expects($this->never())->method('search');

        $result = (new SearchTwigExtension($provider))->search(
            SearchTestPrincipal::create(),
            'query',
            ['min_quality' => 101],
        );

        self::assertSame(0, $result->totalHits);
    }

    #[Test]
    public function search_calls_provider_with_request(): void
    {
        $expected = new SearchResult(
            totalHits: 42,
            totalPages: 3,
            currentPage: 1,
            pageSize: 20,
            hits: [],
        );

        $provider = $this->createMock(SearchProviderInterface::class);
        $provider->expects($this->once())
            ->method('search')
            ->with(
                $this->callback(function (SearchRequest $req): bool {
                    return $req->query === 'indigenous'
                        && $req->filters->topics === ['education']
                        && $req->page === 2;
                }),
                $this->isInstanceOf(\Waaseyaa\Access\AuthorizationPrincipalInterface::class),
            )
            ->willReturn($expected);

        $ext = new SearchTwigExtension($provider);
        $result = $ext->search(SearchTestPrincipal::create(), 'indigenous', ['topic' => 'education'], 2);

        $this->assertSame(42, $result->totalHits);
    }

    #[Test]
    public function it_uses_base_topics_ignoring_user_topic(): void
    {
        $provider = $this->createMock(SearchProviderInterface::class);
        $provider->expects($this->once())
            ->method('search')
            ->with(
                $this->callback(function (SearchRequest $req): bool {
                    return $req->filters->topics === ['indigenous'];
                }),
                $this->isInstanceOf(\Waaseyaa\Access\AuthorizationPrincipalInterface::class),
            )
            ->willReturn(SearchResult::empty());

        $ext = new SearchTwigExtension($provider, baseTopics: ['indigenous']);
        $ext->search(SearchTestPrincipal::create(), 'test', ['topic' => 'education']);
    }

    #[Test]
    public function it_sends_base_topics_when_no_user_topic(): void
    {
        $provider = $this->createMock(SearchProviderInterface::class);
        $provider->expects($this->once())
            ->method('search')
            ->with(
                $this->callback(function (SearchRequest $req): bool {
                    return $req->filters->topics === ['indigenous'];
                }),
                $this->isInstanceOf(\Waaseyaa\Access\AuthorizationPrincipalInterface::class),
            )
            ->willReturn(SearchResult::empty());

        $ext = new SearchTwigExtension($provider, baseTopics: ['indigenous']);
        $ext->search(SearchTestPrincipal::create(), 'test');
    }

    #[Test]
    public function it_uses_user_topic_when_no_base_topics(): void
    {
        $provider = $this->createMock(SearchProviderInterface::class);
        $provider->expects($this->once())
            ->method('search')
            ->with(
                $this->callback(function (SearchRequest $req): bool {
                    return $req->filters->topics === ['education'];
                }),
                $this->isInstanceOf(\Waaseyaa\Access\AuthorizationPrincipalInterface::class),
            )
            ->willReturn(SearchResult::empty());

        $ext = new SearchTwigExtension($provider);
        $ext->search(SearchTestPrincipal::create(), 'test', ['topic' => 'education']);
    }

    #[Test]
    public function query_param_reads_from_get(): void
    {
        $_GET['q'] = 'test query';
        $provider = $this->createStub(SearchProviderInterface::class);
        $ext = new SearchTwigExtension($provider);

        $this->assertSame('test query', $ext->queryParam('q'));
        $this->assertSame('fallback', $ext->queryParam('missing', 'fallback'));

        unset($_GET['q']);
    }
}
