<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector\Tests\Contract;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Vector\EmbeddingStorageInterface;

/** Shared behavior required of every supported storage implementation/driver. */
#[CoversNothing]
abstract class EmbeddingStorageContract extends TestCase
{
    protected EmbeddingStorageInterface $storage;

    #[Test]
    public function replacement_deletion_type_and_exact_string_identity_conform(): void
    {
        $this->storage->store('node', '01', [1, 0]);
        $this->storage->store('node', '1', [0, 1]);
        $this->storage->store('user', '01', [1, 0]);
        $this->storage->store('node', '01', [0, -1]);
        self::assertSame([['id' => '1', 'score' => 1.0], ['id' => '01', 'score' => -1.0]], $this->storage->findSimilar([0, 1], 'node', 10));
        $this->storage->delete('node', '01');
        $this->storage->delete('node', '01');
        self::assertSame([['id' => '01', 'score' => 1.0]], $this->storage->findSimilar([1, 0], 'user', 10));
        $this->storage->store('node', '01', [0, 1]);
        self::assertSame(['01', '1'], array_column($this->storage->findSimilar([0, 1], 'node', 10), 'id'));
    }

    #[Test]
    public function dimensions_zeros_ties_limits_and_empty_results_conform(): void
    {
        $this->storage->store('node', 'z', [1, 0]);
        $this->storage->store('node', 'a', [1, 0]);
        $this->storage->store('node', 'zero', [0, 0]);
        $this->storage->store('node', 'other-dimension', [1, 0, 0]);
        self::assertSame([['id' => 'a', 'score' => 1.0], ['id' => 'z', 'score' => 1.0], ['id' => 'zero', 'score' => 0.0]], $this->storage->findSimilar([1, 0], 'node', 10));
        self::assertCount(1, $this->storage->findSimilar([1, 0], 'node', 1));
        self::assertSame([], $this->storage->findSimilar([1, 0], 'node', 0));
        self::assertSame([], $this->storage->findSimilar([1, 0], 'node', -1));
        self::assertSame([], $this->storage->findSimilar([1, 0], 'absent', 10));
    }

    #[Test]
    public function extreme_finite_vectors_have_finite_cosine_scores(): void
    {
        foreach ([PHP_FLOAT_MAX, PHP_FLOAT_MIN] as $value) {
            $this->storage->store('node', 'same', [$value, $value]);
            $this->storage->store('node', 'opposite', [-$value, -$value]);
            $results = $this->storage->findSimilar([$value, $value], 'node', 10);
            self::assertEqualsWithDelta(1.0, $results[0]['score'], 1e-12);
            self::assertEqualsWithDelta(-1.0, $results[1]['score'], 1e-12);
            self::assertTrue(is_finite($results[0]['score']));
        }
    }

    /** @return iterable<string, array{array}> */
    public static function invalidVectors(): iterable
    {
        yield 'empty' => [[]];
        yield 'map' => [['x' => 1.0]];
        yield 'string' => [['1']];
        yield 'nan' => [[NAN]];
        yield 'infinity' => [[INF]];
    }

    #[Test]
    #[DataProvider('invalidVectors')]
    public function invalid_store_input_refuses_without_destroying_previous_vector(array $invalid): void
    {
        $this->storage->store('node', '1', [1, 0]);
        try {
            $this->storage->store('node', '1', $invalid);
            self::fail('Invalid vector must refuse before replacement.');
        } catch (\InvalidArgumentException) {
            self::assertSame([['id' => '1', 'score' => 1.0]], $this->storage->findSimilar([1, 0], 'node', 10));
        }
    }

    #[Test]
    #[DataProvider('invalidVectors')]
    public function invalid_search_input_refuses_even_when_store_is_empty(array $invalid): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->storage->findSimilar($invalid, 'node', 10);
    }
}
