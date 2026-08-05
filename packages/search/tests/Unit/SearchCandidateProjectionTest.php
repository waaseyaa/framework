<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Search\SearchCandidateProjection;

#[CoversClass(SearchCandidateProjection::class)]
final class SearchCandidateProjectionTest extends TestCase
{
    #[Test]
    public function malformed_projection_bounds_fail_closed(): void
    {
        foreach ([
            static fn() => new SearchCandidateProjection('', 'node', '', ''),
            static fn() => new SearchCandidateProjection('node:1', 'node', str_repeat('t', SearchCandidateProjection::MAX_TITLE_LENGTH + 1), ''),
            static fn() => new SearchCandidateProjection('node:1', 'node', '', str_repeat('b', SearchCandidateProjection::MAX_BODY_LENGTH + 1)),
            static fn() => new SearchCandidateProjection('node:1', 'node', "invalid \xC3\x28", ''),
            static fn() => new SearchCandidateProjection('node:1', 'node', '', '', topics: array_fill(0, SearchCandidateProjection::MAX_TOPICS + 1, 'topic')),
        ] as $create) {
            try {
                $create();
                self::fail('Expected malformed search projection to be rejected.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
