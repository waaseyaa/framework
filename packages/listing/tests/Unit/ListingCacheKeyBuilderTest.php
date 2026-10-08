<?php

declare(strict_types=1);

namespace Waaseyaa\Listing\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Listing\ExposedFilterValues;
use Waaseyaa\Listing\Filter;
use Waaseyaa\Listing\ListingCacheKeyBuilder;
use Waaseyaa\Listing\ListingDefinition;

/**
 * Unit tests for {@see ListingCacheKeyBuilder}.
 *
 * Covers FR-037 — deterministic cache-key composition:
 *   `listing:<def-hash>:<exposed-hash>:<ctx-hash>` where each hash is a
 *   16-hex-char SHA-256 prefix over canonical JSON.
 *
 * Determinism is critical for cache-key parity across PHP workers: two
 * processes with identical inputs MUST produce identical keys.
 */
#[CoversClass(ListingCacheKeyBuilder::class)]
final class ListingCacheKeyBuilderTest extends TestCase
{
    private ListingCacheKeyBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ListingCacheKeyBuilder();
    }

    #[Test]
    public function keyIsDeterministicForSameInputs(): void
    {
        $def = new ListingDefinition(id: 'recent', entityType: 'node', pageSize: 20);
        $exposed = new ExposedFilterValues(['q' => 'hello']);
        $contexts = ['user.roles' => 'admin', 'language.content' => 'en'];

        $first = $this->builder->build($def, $exposed, $contexts);
        $second = $this->builder->build($def, $exposed, $contexts);

        self::assertSame($first, $second, 'Same inputs must yield identical cache keys.');
    }

    #[Test]
    public function keyDiffersForDifferentDefinitions(): void
    {
        $defA = new ListingDefinition(id: 'a', entityType: 'node');
        $defB = new ListingDefinition(id: 'b', entityType: 'node');
        $exposed = new ExposedFilterValues();
        $contexts = ['user.roles' => ''];

        self::assertNotSame(
            $this->builder->build($defA, $exposed, $contexts),
            $this->builder->build($defB, $exposed, $contexts),
            'Different listing definitions must produce different keys.',
        );
    }

    #[Test]
    public function keyDiffersForDifferentExposedValues(): void
    {
        $def = new ListingDefinition(id: 'search', entityType: 'node');
        $contexts = ['user.roles' => ''];
        $exposedA = new ExposedFilterValues(['q' => 'foo']);
        $exposedB = new ExposedFilterValues(['q' => 'bar']);

        self::assertNotSame(
            $this->builder->build($def, $exposedA, $contexts),
            $this->builder->build($def, $exposedB, $contexts),
            'Different exposed-filter values must produce different keys.',
        );
    }

    #[Test]
    public function keyDiffersForDifferentContextValues(): void
    {
        $def = new ListingDefinition(id: 'localized', entityType: 'node');
        $exposed = new ExposedFilterValues();

        $keyEn = $this->builder->build($def, $exposed, ['language.content' => 'en']);
        $keyFr = $this->builder->build($def, $exposed, ['language.content' => 'fr']);

        self::assertNotSame($keyEn, $keyFr, 'Different context values must produce different keys.');
    }

    #[Test]
    public function keyOrderInvariantOnContextMap(): void
    {
        $def = new ListingDefinition(id: 'ordered', entityType: 'node');
        $exposed = new ExposedFilterValues();

        $forward = [
            'language.content' => 'en',
            'url.query.page'   => '2',
            'user.roles'       => 'editor',
        ];
        $reverse = [
            'user.roles'       => 'editor',
            'url.query.page'   => '2',
            'language.content' => 'en',
        ];

        self::assertSame(
            $this->builder->build($def, $exposed, $forward),
            $this->builder->build($def, $exposed, $reverse),
            'Context-map insertion order must not influence the cache key (ksort invariance).',
        );
    }

    #[Test]
    public function keyFormatMatchesContract(): void
    {
        $def = new ListingDefinition(id: 'fmt', entityType: 'node');
        $exposed = new ExposedFilterValues(['q' => 'x']);
        $contexts = ['user.roles' => 'admin'];

        $key = $this->builder->build($def, $exposed, $contexts);

        self::assertMatchesRegularExpression(
            '/^listing:[0-9a-f]{16}:[0-9a-f]{16}:[0-9a-f]{16}$/',
            $key,
            'Cache key must match FR-037 format: listing:<16hex>:<16hex>:<16hex>.',
        );
    }

    #[Test]
    public function keyLengthIsStableAroundSixtyChars(): void
    {
        $def = new ListingDefinition(id: 'len', entityType: 'node');
        $exposed = new ExposedFilterValues();
        $contexts = ['user.roles' => ''];

        $key = $this->builder->build($def, $exposed, $contexts);

        // 'listing:' (8) + 3 * 16 (hex) + 2 (colons) = 58.
        self::assertSame(58, strlen($key), 'Cache-key length must be exactly 58 chars (8 prefix + 3*16 + 2 colons).');
    }

    #[Test]
    public function keyDistinctEvenWithSubtleInputChanges(): void
    {
        // Collision smoke — small structural differences must yield different
        // keys. Not exhaustive; the upstream cacheKeyHash() invariants
        // (canonical JSON sort + SHA-256) carry the rest.
        $defA = new ListingDefinition(id: 'subtle', entityType: 'node', pageSize: 20);
        $defB = new ListingDefinition(id: 'subtle', entityType: 'node', pageSize: 21);
        $exposed = new ExposedFilterValues();
        $contexts = ['user.roles' => ''];

        self::assertNotSame(
            $this->builder->build($defA, $exposed, $contexts),
            $this->builder->build($defB, $exposed, $contexts),
            'Changing a single scalar field in the definition must change the key.',
        );

        // A filter difference must also propagate.
        $defC = new ListingDefinition(
            id: 'subtle',
            entityType: 'node',
            filters: [Filter::eq('status', 1)],
        );
        $defD = new ListingDefinition(
            id: 'subtle',
            entityType: 'node',
            filters: [Filter::eq('status', 0)],
        );

        self::assertNotSame(
            $this->builder->build($defC, $exposed, $contexts),
            $this->builder->build($defD, $exposed, $contexts),
            'Changing a filter value must change the key.',
        );
    }

    #[Test]
    public function keyHandlesEmptyContextValues(): void
    {
        $def = new ListingDefinition(id: 'empty_ctx', entityType: 'node');
        $exposed = new ExposedFilterValues();

        $key = $this->builder->build($def, $exposed, []);

        // Even with an empty context-map, the key must still be well-formed.
        self::assertMatchesRegularExpression(
            '/^listing:[0-9a-f]{16}:[0-9a-f]{16}:[0-9a-f]{16}$/',
            $key,
            'Empty context-map should still produce a valid cache key.',
        );
    }
    #[Test]
    public function contextHashPreservesEstablishedBytes(): void
    {
        $def = new ListingDefinition(id: 'golden', entityType: 'node');
        $exposed = new ExposedFilterValues();
        foreach ([
            [[], '4f53cda18c2baa0c'],
            [['language.content' => 'fr', 'url.query.q' => 'café/a'], '0dea13b41973db21'],
            [['url.query.q' => 'café/a', 'language.content' => 'fr'], '0dea13b41973db21'],
            [['10' => 'ten', '2' => 'two'], 'd7d8369c8035a81d'],
            [['2' => 'two', '10' => 'ten'], 'd7d8369c8035a81d'],
            [['0' => 'zero', '1' => 'one'], '4c80b4a57a85f3a4'],
        ] as [$contexts, $expected]) {
            self::assertSame($expected, substr($this->builder->build($def, $exposed, $contexts), -16));
        }
    }

}
