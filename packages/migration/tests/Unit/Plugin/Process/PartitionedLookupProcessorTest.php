<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Unit\Plugin\Process;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Migration\Exception\ProcessException;
use Waaseyaa\Migration\Plugin\Process\PartitionedLookupProcessor;
use Waaseyaa\Migration\Plugin\ProcessContext;
use Waaseyaa\Migration\Plugin\ReservedPluginIds;
use Waaseyaa\Migration\Plugin\SourceRecord;
use Waaseyaa\Migration\Plugin\WriteResult;
use Waaseyaa\Migration\SourceId;

#[CoversClass(PartitionedLookupProcessor::class)]
final class PartitionedLookupProcessorTest extends TestCase
{
    #[Test]
    public function routes_each_item_and_maps_each_write_result(): void
    {
        $lookups = [];
        $context = new ProcessContext(
            new SourceRecord('mixed', ['items' => [
                ['partition' => 'a', 'id' => '1'],
                ['partition' => 'b', 'id' => '2'],
            ]]),
            'content',
            'references',
            function (string $migrationId, SourceId $sourceId) use (&$lookups): WriteResult {
                $lookups[] = [$migrationId, $sourceId->keys];
                return new WriteResult('entity', 'uuid-' . $sourceId->keys['id'], 'hash', 'run', '2026-01-01T00:00:00Z');
            },
        );
        $processor = $this->processor(static fn (WriteResult $result): string => 'ref:' . $result->destinationUuid);

        self::assertSame(['ref:uuid-1', 'ref:uuid-2'], $processor->transform(null, $context));
        self::assertSame([['terms_a', ['id' => '1']], ['terms_b', ['id' => '2']]], $lookups);
        self::assertSame(ReservedPluginIds::PARTITIONED_LOOKUP, $processor->id());
        self::assertSame('stable', $processor->stability());
    }

    #[Test]
    public function null_miss_skips_only_the_unresolved_item(): void
    {
        $context = new ProcessContext(
            new SourceRecord('mixed', []),
            'content',
            'references',
            static fn (string $migrationId, SourceId $sourceId): ?WriteResult => $sourceId->keys['id'] === '2'
                ? new WriteResult('entity', 'uuid-2', 'hash', 'run', '2026-01-01T00:00:00Z')
                : null,
        );

        self::assertSame(['uuid-2'], $this->processor()->transform([
            ['partition' => 'a', 'id' => '1'],
            ['partition' => 'b', 'id' => '2'],
        ], $context));
    }

    #[Test]
    public function fail_miss_raises_typed_process_exception(): void
    {
        $context = new ProcessContext(
            new SourceRecord('mixed', ['items' => [['partition' => 'a', 'id' => '1']]]),
            'content',
            'references',
            static fn (): null => null,
        );
        $processor = new PartitionedLookupProcessor(
            'items',
            static fn (): string => 'terms_a',
            static fn (): SourceId => new SourceId('term', ['id' => '1']),
            onMiss: PartitionedLookupProcessor::ON_MISS_FAIL,
        );

        $this->expectException(ProcessException::class);
        $this->expectExceptionMessage('No id-map row');
        $processor->transform(null, $context);
    }

    private function processor(?\Closure $resultFor = null): PartitionedLookupProcessor
    {
        return new PartitionedLookupProcessor(
            'items',
            static fn (mixed $item): ?string => is_array($item) && isset($item['partition'])
                ? 'terms_' . $item['partition']
                : null,
            static fn (mixed $item): ?SourceId => is_array($item) && isset($item['id'])
                ? new SourceId('term', ['id' => $item['id']])
                : null,
            $resultFor,
        );
    }
}
