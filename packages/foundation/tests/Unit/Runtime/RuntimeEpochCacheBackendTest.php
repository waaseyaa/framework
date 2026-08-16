<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Cache\Backend\MemoryBackend;
use Waaseyaa\Foundation\Runtime\RuntimeEpochCacheBackend;

final class RuntimeEpochCacheBackendTest extends TestCase
{
    #[Test]
    public function differentEpochsCannotReadOrInvalidateEachOthersEntries(): void
    {
        $inner = new MemoryBackend();
        $first = new RuntimeEpochCacheBackend($inner, 'configuration:first');
        $second = new RuntimeEpochCacheBackend($inner, 'configuration:second');
        $first->set('entity:1', ['name' => 'A'], tags: ['mcp_read:entity:node:1']);
        $second->set('entity:1', ['name' => 'B'], tags: ['mcp_read:entity:node:1']);

        self::assertSame(['name' => 'A'], $first->get('entity:1')?->data);
        self::assertSame(['name' => 'B'], $second->get('entity:1')?->data);
        $first->invalidateByTags(['mcp_read:entity:node:1']);
        self::assertFalse($first->get('entity:1')?->valid ?? false);
        self::assertTrue($second->get('entity:1')?->valid ?? false);
    }

    #[Test]
    public function bulkReadsPreservePublicKeysAndReportOnlyOriginalMisses(): void
    {
        $cache = new RuntimeEpochCacheBackend(new MemoryBackend(), 'configuration:bulk');
        $cache->set('a', 1);
        $cache->set('b', 2);
        $cids = ['a', 'missing', 'b'];

        $items = $cache->getMultiple($cids);

        self::assertSame(['a', 'b'], array_keys($items));
        self::assertSame(['missing'], $cids);
        self::assertSame('a', $items['a']->cid);
    }

    #[Test]
    public function clearingOneEpochLeavesAnotherEpochReadable(): void
    {
        $inner = new MemoryBackend();
        $first = new RuntimeEpochCacheBackend($inner, 'configuration:first');
        $second = new RuntimeEpochCacheBackend($inner, 'configuration:second');
        $first->set('x', 'old');
        $second->set('x', 'new');

        $first->deleteAll();

        self::assertFalse($first->get('x')?->valid ?? false);
        self::assertSame('new', $second->get('x')?->data);
    }
}
