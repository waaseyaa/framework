<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Unit\Plugin\Process;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Migration\Plugin\Process\ConcatProcessor;
use Waaseyaa\Migration\Plugin\Process\DefaultValueProcessor;
use Waaseyaa\Migration\Plugin\Process\HtmlSanitizeProcessor;
use Waaseyaa\Migration\Plugin\Process\LookupProcessor;
use Waaseyaa\Migration\Plugin\Process\PassThroughProcessor;
use Waaseyaa\Migration\Plugin\Process\PartitionedLookupProcessor;
use Waaseyaa\Migration\Plugin\Process\TypeCoerceProcessor;
use Waaseyaa\Migration\Plugin\ReservedPluginIds;

/**
 * Drift guard: asserts that the set of ids returned by the shipped
 * framework-reserved processors equals {@see ReservedPluginIds::ALL}.
 *
 * If a future WP adds a seventh processor, or renames one, this test breaks
 * loudly — forcing the change to land alongside an update to
 * `ReservedPluginIds::ALL` (and the documented stable surface).
 *
 * @spec §5.4 — reserved-id surface consistency
 */
#[CoversNothing]
final class ReservedPluginIdsParityTest extends TestCase
{
    #[Test]
    public function shipped_processors_match_reserved_id_set(): void
    {
        $shipped = [
            (new PassThroughProcessor('x'))->id(),
            (new HtmlSanitizeProcessor('x'))->id(),
            (new LookupProcessor('x', 'm'))->id(),
            (new ConcatProcessor([]))->id(),
            (new TypeCoerceProcessor('string'))->id(),
            (new DefaultValueProcessor(null))->id(),
            $this->partitionedLookup()->id(),
        ];

        $shippedSorted = $shipped;
        sort($shippedSorted);

        $reservedSorted = ReservedPluginIds::ALL;
        sort($reservedSorted);

        self::assertSame(
            $reservedSorted,
            $shippedSorted,
            'Set of shipped process-plugin ids must equal ReservedPluginIds::ALL.',
        );
    }

    #[Test]
    public function every_shipped_id_is_reported_reserved(): void
    {
        $ids = [
            (new PassThroughProcessor('x'))->id(),
            (new HtmlSanitizeProcessor('x'))->id(),
            (new LookupProcessor('x', 'm'))->id(),
            (new ConcatProcessor([]))->id(),
            (new TypeCoerceProcessor('string'))->id(),
            (new DefaultValueProcessor(null))->id(),
            $this->partitionedLookup()->id(),
        ];

        foreach ($ids as $id) {
            self::assertTrue(
                ReservedPluginIds::isReserved($id),
                \sprintf('Shipped id %s must be reported reserved.', var_export($id, true)),
            );
        }
    }

    #[Test]
    public function every_processor_reports_stable_marker(): void
    {
        self::assertSame('stable', (new PassThroughProcessor('x'))->stability());
        self::assertSame('stable', (new HtmlSanitizeProcessor('x'))->stability());
        self::assertSame('stable', (new LookupProcessor('x', 'm'))->stability());
        self::assertSame('stable', (new ConcatProcessor([]))->stability());
        self::assertSame('stable', (new TypeCoerceProcessor('string'))->stability());
        self::assertSame('stable', (new DefaultValueProcessor(null))->stability());
        self::assertSame('stable', $this->partitionedLookup()->stability());
    }

    private function partitionedLookup(): PartitionedLookupProcessor
    {
        return new PartitionedLookupProcessor(
            'items',
            static fn (): string => 'partition',
            static fn (): \Waaseyaa\Migration\SourceId => new \Waaseyaa\Migration\SourceId('source', ['id' => '1']),
        );
    }
}
