<?php

declare(strict_types=1);

namespace Waaseyaa\Listing\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Listing\ExposedFilterValues;

/**
 * Unit tests for {@see ExposedFilterValues}.
 *
 * Covers FR-042 (typed value object) and FR-037 (cache-key-hash
 * determinism, mirrored by {@see ListingCacheKeyBuilder}).
 */
#[CoversClass(ExposedFilterValues::class)]
final class ExposedFilterValuesTest extends TestCase
{
    #[Test]
    public function cacheHashPreservesOriginalNumericAndListIdentity(): void
    {
        self::assertSame('3b70e61ead98ed15', new ExposedFilterValues(['float' => 1.0])->cacheKeyHash());
        self::assertSame('b211e95afc03b3b0', new ExposedFilterValues(['float' => 1])->cacheKeyHash());
        self::assertSame('9ff6e484fe32f54e', new ExposedFilterValues(['map' => [10 => 'ten', 2 => 'two'], 'list' => ['b', 'a']])->cacheKeyHash());
        self::assertSame('9ff6e484fe32f54e', new ExposedFilterValues(['list' => ['b', 'a'], 'map' => [2 => 'two', 10 => 'ten']])->cacheKeyHash());
        self::assertNotSame('9ff6e484fe32f54e', new ExposedFilterValues(['map' => [2 => 'two', 10 => 'ten'], 'list' => ['a', 'b']])->cacheKeyHash());
    }
    #[Test]
    public function emptyConstructionReturnsEmptyAll(): void
    {
        $values = new ExposedFilterValues();
        self::assertSame([], $values->all());
        self::assertFalse($values->has('q'));
        self::assertNull($values->get('q'));
    }

    #[Test]
    public function getReturnsTheStoredValue(): void
    {
        $values = new ExposedFilterValues(['q' => 'hello', 'status' => 1]);
        self::assertSame('hello', $values->get('q'));
        self::assertSame(1, $values->get('status'));
    }

    #[Test]
    public function hasIsTrueForExistingKeyEvenWithNullValue(): void
    {
        $values = new ExposedFilterValues(['flag' => null]);
        self::assertTrue($values->has('flag'));
        self::assertNull($values->get('flag'));
    }

    #[Test]
    public function getReturnsNullForMissingKey(): void
    {
        $values = new ExposedFilterValues(['q' => 'present']);
        self::assertNull($values->get('missing'));
        self::assertFalse($values->has('missing'));
    }

    #[Test]
    public function allReturnsFullMap(): void
    {
        $map = ['q' => 'hello', 'status' => 1, 'tags' => ['a', 'b']];
        $values = new ExposedFilterValues($map);
        self::assertSame($map, $values->all());
    }

    #[Test]
    public function cacheKeyHashIsSixteenLowercaseHexChars(): void
    {
        $values = new ExposedFilterValues(['q' => 'hello']);
        $hash = $values->cacheKeyHash();
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $hash);
    }

    #[Test]
    public function cacheKeyHashIsDeterministicForSameInput(): void
    {
        $a = new ExposedFilterValues(['q' => 'hello', 'status' => 1]);
        $b = new ExposedFilterValues(['q' => 'hello', 'status' => 1]);
        self::assertSame($a->cacheKeyHash(), $b->cacheKeyHash());
    }

    #[Test]
    public function cacheKeyHashIsOrderInvariantForAssociativeKeys(): void
    {
        $a = new ExposedFilterValues(['q' => 'hello', 'status' => 1]);
        $b = new ExposedFilterValues(['status' => 1, 'q' => 'hello']);
        self::assertSame(
            $a->cacheKeyHash(),
            $b->cacheKeyHash(),
            'Canonical JSON ksort must yield order-invariant digests across PHP workers.',
        );
    }

    #[Test]
    public function cacheKeyHashRecursivelySortsNestedAssociativeArrays(): void
    {
        $a = new ExposedFilterValues(['filter' => ['kind' => 'node', 'state' => 'published']]);
        $b = new ExposedFilterValues(['filter' => ['state' => 'published', 'kind' => 'node']]);
        self::assertSame($a->cacheKeyHash(), $b->cacheKeyHash());
    }

    #[Test]
    public function cacheKeyHashPreservesListOrderingAsSemantic(): void
    {
        // Lists are positional — distinct orderings MUST hash differently.
        $a = new ExposedFilterValues(['tags' => ['a', 'b']]);
        $b = new ExposedFilterValues(['tags' => ['b', 'a']]);
        self::assertNotSame($a->cacheKeyHash(), $b->cacheKeyHash());
    }

    #[Test]
    public function cacheKeyHashDiffersForDifferentInputs(): void
    {
        $a = new ExposedFilterValues(['q' => 'hello']);
        $b = new ExposedFilterValues(['q' => 'world']);
        self::assertNotSame($a->cacheKeyHash(), $b->cacheKeyHash());
    }

    #[Test]
    public function emptyMapStillHashesToValidShape(): void
    {
        $values = new ExposedFilterValues();
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $values->cacheKeyHash());
    }
}
