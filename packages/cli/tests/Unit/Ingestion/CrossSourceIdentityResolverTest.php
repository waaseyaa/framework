<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Ingestion;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Ingestion\CrossSourceIdentityResolver;

#[CoversClass(CrossSourceIdentityResolver::class)]
final class CrossSourceIdentityResolverTest extends TestCase
{
    #[Test]
    public function it_binds_rows_to_a_canonical_identity_deterministically(): void
    {
        $resolver = new CrossSourceIdentityResolver();
        $result = $resolver->resolve([
            [
                'source_id' => 'b-row',
                'source_uri' => 'https://example.com/knowledge/item?a=1',
                'ownership' => 'third_party',
            ],
            [
                'source_id' => 'a-row',
                'source_uri' => 'https://example.com/knowledge/item?b=2',
                'ownership' => 'first_party',
            ],
            [
                'source_id' => 'c-row',
                'source_uri' => 'https://example.com/knowledge/other',
                'ownership' => 'third_party',
            ],
        ]);

        $this->assertCount(3, $result['rows']);
        $this->assertCount(2, $result['diagnostics']);
        $this->assertSame('identity.collision_resolved', $result['diagnostics'][0]['code']);
        $this->assertSame('a-row', $result['diagnostics'][0]['context']['canonical_source_id']);
        $this->assertSame(['a-row', 'b-row'], $result['diagnostics'][0]['context']['member_source_ids']);
    }

    #[Test]
    public function it_produces_stable_ordering_for_rows_and_diagnostics(): void
    {
        $resolver = new CrossSourceIdentityResolver();
        $input = [
            [
                'source_id' => 'z-row',
                'source_uri' => 'https://example.com/c',
                'ownership' => 'third_party',
            ],
            [
                'source_id' => 'a-row',
                'source_uri' => 'https://example.com/a',
                'ownership' => 'first_party',
            ],
            [
                'source_id' => 'b-row',
                'source_uri' => 'https://example.com/b',
                'ownership' => 'federated',
            ],
        ];

        $first = $resolver->resolve($input);
        $second = $resolver->resolve(array_reverse($input));

        $this->assertSame($first, $second);
    }
}
