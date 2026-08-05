<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Api\ContentSearch\AtomicRateLimiterAdapter;
use Waaseyaa\Api\ContentSearch\ContentSearchBoundaryException;
use Waaseyaa\Api\ContentSearch\ContentSearchQuery;
use Waaseyaa\Api\ContentSearch\SearchPackageContentSearchAdapter;
use Waaseyaa\Search\SearchProviderInterface;
use Waaseyaa\Search\SearchResult;

#[CoversClass(SearchPackageContentSearchAdapter::class)]
#[CoversClass(AtomicRateLimiterAdapter::class)]
final class ContentSearchOptionalAdapterTest extends TestCase
{
    #[Test]
    public function incompatible_optional_objects_fail_at_the_adapter_boundary(): void
    {
        foreach ([SearchPackageContentSearchAdapter::class, AtomicRateLimiterAdapter::class] as $adapter) {
            try {
                new $adapter(new \stdClass());
                self::fail('An incompatible optional object must be refused.');
            } catch (ContentSearchBoundaryException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function invalid_optional_result_data_never_becomes_a_partial_or_coerced_page(): void
    {
        $provider = $this->createStub(SearchProviderInterface::class);
        $provider->method('search')->willReturn(new SearchResult(
            totalHits: -1,
            totalPages: 0,
            currentPage: 1,
            pageSize: 20,
            hits: [],
        ));
        $adapter = new SearchPackageContentSearchAdapter($provider);

        $this->expectException(ContentSearchBoundaryException::class);
        $adapter->search(
            new ContentSearchQuery('community'),
            new AuthorizationPrincipal(0, false, [], [], 'anonymous'),
        );
    }
}
