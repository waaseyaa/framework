<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Unit\Plugin\Source;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Migration\Plugin\Source\FilteredSource;
use Waaseyaa\Migration\Plugin\SourceRecord;
use Waaseyaa\Migration\PluginFixtures\InMemorySource;

#[CoversClass(FilteredSource::class)]
final class FilteredSourceTest extends TestCase
{
    #[Test]
    public function filters_lazily_and_delegates_identity_and_stability(): void
    {
        $source = new InMemorySource('mixed', [
            new SourceRecord('row', ['id' => '1', 'bundle' => 'one']),
            new SourceRecord('row', ['id' => '2', 'bundle' => 'two']),
        ], sourceType: 'external');
        $filtered = new FilteredSource(
            $source,
            static fn (SourceRecord $record): bool => $record->field('bundle') === 'two',
            'mixed_two',
        );

        $records = iterator_to_array($filtered->records(), false);

        self::assertSame('mixed_two', $filtered->id());
        self::assertSame('stable', $filtered->stability());
        self::assertNull($filtered->count());
        self::assertCount(1, $records);
        self::assertSame('2', $records[0]->field('id'));
        self::assertSame($source->sourceIdFor($records[0])->hash(), $filtered->sourceIdFor($records[0])->hash());
    }

    #[Test]
    public function rejects_empty_plugin_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FilteredSource(new InMemorySource('mixed', []), static fn (): bool => true, '');
    }
}
