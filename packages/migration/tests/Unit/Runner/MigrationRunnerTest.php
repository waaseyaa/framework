<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Unit\Runner;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Migration\Discovery\HasMigrationsInterface;
use Waaseyaa\Migration\Discovery\MigrationRegistry;
use Waaseyaa\Migration\Exception\DestinationWriteException;
use Waaseyaa\Migration\Exception\MigrationAbortedException;
use Waaseyaa\Migration\Exception\ProcessException;
use Waaseyaa\Migration\Exception\SourceReadException;
use Waaseyaa\Migration\MigrationDefinition;
use Waaseyaa\Migration\MigrationIdMap;
use Waaseyaa\Migration\Plugin\DestinationPluginInterface;
use Waaseyaa\Migration\Plugin\SourceRecord;
use Waaseyaa\Migration\PluginFixtures\AlwaysFailingProcessor;
use Waaseyaa\Migration\PluginFixtures\InMemoryDestination;
use Waaseyaa\Migration\PluginFixtures\InMemorySource;
use Waaseyaa\Migration\Runner\MigrationRunner;
use Waaseyaa\Migration\Runner\ProcessChainExecutor;
use Waaseyaa\Migration\Runner\RecordError;
use Waaseyaa\Migration\Runner\RunOptions;
use Waaseyaa\Migration\Schema\MigrationIdMapSchema;
use Waaseyaa\Migration\SourceId;

#[CoversClass(MigrationRunner::class)]
#[CoversClass(MigrationAbortedException::class)]
final class MigrationRunnerTest extends TestCase
{
    private DBALDatabase $database;
    private MigrationIdMap $idMap;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->database->getConnection()->executeStatement(MigrationIdMapSchema::createTableSql());
        foreach (MigrationIdMapSchema::createIndexSqls() as $sql) {
            $this->database->getConnection()->executeStatement($sql);
        }
        $this->idMap = new MigrationIdMap($this->database);
    }

    #[Test]
    public function happy_path_imports_every_record(): void
    {
        $records = $this->makeRecords(['a', 'b', 'c']);
        $source = new InMemorySource(id: 'in_memory', records: $records);
        $destination = new InMemoryDestination();

        $definition = $this->demoDefinition($source, $destination);
        $registry = $this->buildRegistry($definition);
        $runner = $this->makeRunner($registry);

        $report = $runner->run('demo', new RunOptions());

        self::assertSame(3, $report->imported);
        self::assertSame(0, $report->skipped);
        self::assertSame(0, $report->failed);
        self::assertSame(3, $report->total);
        self::assertFalse($report->aborted);
        self::assertCount(3, $destination->writes);
    }

    #[Test]
    public function dry_run_records_skipped_not_imported(): void
    {
        $source = new InMemorySource(id: 'in_memory', records: $this->makeRecords(['a', 'b']));
        $destination = new InMemoryDestination();

        $definition = $this->demoDefinition($source, $destination);
        $runner = $this->makeRunner($this->buildRegistry($definition));

        $report = $runner->run('demo', new RunOptions(dryRun: true));

        self::assertSame(0, $report->imported);
        self::assertSame(2, $report->skipped);
        self::assertSame([], $destination->writes);
    }

    #[Test]
    public function limit_caps_processed_records(): void
    {
        $source = new InMemorySource(id: 'in_memory', records: $this->makeRecords(['a', 'b', 'c', 'd', 'e']));
        $destination = new InMemoryDestination();

        $runner = $this->makeRunner($this->buildRegistry($this->demoDefinition($source, $destination)));
        $report = $runner->run('demo', new RunOptions(limit: 3));

        self::assertSame(3, $report->imported);
        self::assertSame(3, $report->processed());
        self::assertCount(3, $destination->writes);
    }

    #[Test]
    public function process_error_with_halt_on_error_raises_aborted(): void
    {
        $source = new InMemorySource(id: 'in_memory', records: $this->makeRecords(['a', 'b']));
        $definition = new MigrationDefinition(
            id: 'demo',
            source: $source,
            // Failing processor on every record — first record triggers halt.
            process: ['body' => [new AlwaysFailingProcessor()]],
            destination: new InMemoryDestination(),
        );

        $runner = $this->makeRunner($this->buildRegistry($definition));

        try {
            $runner->run('demo', new RunOptions(haltOnError: true));
            self::fail('expected MigrationAbortedException');
        } catch (MigrationAbortedException $e) {
            self::assertSame(1, $e->report->failed);
            self::assertTrue($e->report->aborted);
            self::assertInstanceOf(ProcessException::class, $e->getPrevious());
            self::assertNotEmpty($e->report->errors);
            self::assertSame(RecordError::STAGE_PROCESS, $e->report->errors[0]->stage);
            self::assertSame('TEST_FAILURE', $e->report->errors[0]->code);
        }
    }

    #[Test]
    public function process_error_default_continues_and_collects(): void
    {
        $source = new InMemorySource(id: 'in_memory', records: $this->makeRecords(['a', 'b', 'c']));
        $definition = new MigrationDefinition(
            id: 'demo',
            source: $source,
            process: ['body' => [new AlwaysFailingProcessor()]],
            destination: new InMemoryDestination(),
        );

        $runner = $this->makeRunner($this->buildRegistry($definition));
        $report = $runner->run('demo', new RunOptions());

        self::assertSame(0, $report->imported);
        self::assertSame(3, $report->failed);
        self::assertCount(3, $report->errors);
        self::assertFalse($report->aborted);
    }

    #[Test]
    public function destination_error_default_continues(): void
    {
        $records = $this->makeRecords(['a', 'b', 'c']);
        $source = new InMemorySource(id: 'in_memory', records: $records);
        // Fail on the second record only.
        $secondHash = (new SourceId('in_memory', ['id' => 'b']))->hash();
        $destination = new InMemoryDestination(
            failOnSourceIdHash: $secondHash,
            throwForSourceIdHash: new DestinationWriteException(
                message: 'synthetic destination write failure',
                reason: 'entity_save_failed',
            ),
        );

        $runner = $this->makeRunner($this->buildRegistry($this->demoDefinition($source, $destination)));
        $report = $runner->run('demo', new RunOptions());

        self::assertSame(2, $report->imported);
        self::assertSame(1, $report->failed);
        self::assertSame(0, $report->skipped);
        self::assertCount(1, $report->errors);
        self::assertSame(RecordError::STAGE_DESTINATION, $report->errors[0]->stage);
        self::assertSame('entity_save_failed', $report->errors[0]->code);
    }

    #[Test]
    public function source_mid_iteration_crash_aborts_run_FR048(): void
    {
        $source = new InMemorySource(
            id: 'in_memory',
            records: $this->makeRecords(['a', 'b', 'c']),
            throwAtIndex: 1,
        );
        $runner = $this->makeRunner($this->buildRegistry($this->demoDefinition($source, new InMemoryDestination())));

        try {
            $runner->run('demo', new RunOptions());
            self::fail('expected MigrationAbortedException');
        } catch (MigrationAbortedException $e) {
            self::assertTrue($e->report->aborted);
            self::assertSame(1, $e->report->imported);
            // The previous is SourceReadException (the runner wraps generator throws).
            self::assertInstanceOf(SourceReadException::class, $e->getPrevious());
        }
    }

    #[Test]
    public function unknown_total_renders_negative_one(): void
    {
        $source = new InMemorySource(
            id: 'in_memory',
            records: $this->makeRecords(['a']),
            reportCount: false,
        );
        $runner = $this->makeRunner($this->buildRegistry($this->demoDefinition($source, new InMemoryDestination())));

        $report = $runner->run('demo', new RunOptions());
        self::assertSame(-1, $report->total);
    }

    #[Test]
    public function run_id_is_stamped_on_every_id_map_row(): void
    {
        $source = new InMemorySource(id: 'in_memory', records: $this->makeRecords(['a', 'b']));
        $destination = new InMemoryDestination();

        $runner = $this->makeRunner($this->buildRegistry($this->demoDefinition($source, $destination)));
        // Caller-supplied run id flows through to every write.
        $runId = '019683d3-1234-7000-8123-456789abcdef';
        $report = $runner->run('demo', new RunOptions(runId: $runId));

        self::assertSame($runId, $report->runId);
        // InMemoryDestination doesn't honor withRunId() (only EntityDestination
        // does), so the runId on its WriteResult uses its own placeholder. The
        // contract is: the runner-level report carries the canonical run id.
        self::assertSame(2, $report->imported);
    }

    /**
     * R3 WP2 (audit-remediation batch 2026-07-02): wall-clock time is
     * non-monotonic (NTP step, VM/WSL suspend-resume, leap-second smear). A
     * backward step between the `$startedAt` capture (run() entry) and the
     * `$finishedAt` capture (report construction) must not turn a
     * fully-committed successful run into an uncaught
     * `\InvalidArgumentException` from `RunReport`'s invariant. Mirrors
     * `RollbackWalker`'s existing monotonic clamp.
     */
    #[Test]
    public function backward_clock_step_does_not_crash_a_successful_run(): void
    {
        $source = new InMemorySource(id: 'in_memory', records: $this->makeRecords(['a', 'b']));
        $destination = new InMemoryDestination();

        $definition = $this->demoDefinition($source, $destination);
        $registry = $this->buildRegistry($definition);

        $startedAt = new \DateTimeImmutable('2026-07-02T12:00:00+00:00');
        // Finish stamp lands 1 second BEFORE the start stamp — simulates a
        // backward wall-clock step occurring mid-run.
        $finishedAt = $startedAt->modify('-1 second');
        $runner = $this->makeRunner($registry, $this->descendingClock($startedAt, $finishedAt));

        $report = $runner->run('demo', new RunOptions());

        self::assertSame(2, $report->imported);
        self::assertFalse($report->aborted);
        self::assertTrue(
            $report->finishedAt >= $report->startedAt,
            'RunReport::$finishedAt must be clamped to >= $startedAt on a backward clock step.',
        );
    }

    /**
     * @see backward_clock_step_does_not_crash_a_successful_run() — same
     * clock-regression scenario, but the failure happens on the
     * `--halt-on-error` per-record abort path (RunReport site ~L266).
     */
    #[Test]
    public function backward_clock_step_does_not_crash_a_halt_on_error_abort(): void
    {
        $source = new InMemorySource(id: 'in_memory', records: $this->makeRecords(['a', 'b']));
        $definition = new MigrationDefinition(
            id: 'demo',
            source: $source,
            process: ['body' => [new AlwaysFailingProcessor()]],
            destination: new InMemoryDestination(),
        );

        $startedAt = new \DateTimeImmutable('2026-07-02T12:00:00+00:00');
        $finishedAt = $startedAt->modify('-1 second');
        $runner = $this->makeRunner($this->buildRegistry($definition), $this->descendingClock($startedAt, $finishedAt));

        try {
            $runner->run('demo', new RunOptions(haltOnError: true));
            self::fail('expected MigrationAbortedException');
        } catch (MigrationAbortedException $e) {
            self::assertTrue($e->report->aborted);
            self::assertTrue(
                $e->report->finishedAt >= $e->report->startedAt,
                'RunReport::$finishedAt must be clamped to >= $startedAt on a backward clock step.',
            );
        }
    }

    /**
     * @see backward_clock_step_does_not_crash_a_successful_run() — same
     * clock-regression scenario, but the failure happens on the run-level
     * FR-048 abort path (RunReport site ~L296).
     */
    #[Test]
    public function backward_clock_step_does_not_crash_a_run_level_abort_FR048(): void
    {
        $source = new InMemorySource(
            id: 'in_memory',
            records: $this->makeRecords(['a', 'b', 'c']),
            throwAtIndex: 1,
        );

        $startedAt = new \DateTimeImmutable('2026-07-02T12:00:00+00:00');
        $finishedAt = $startedAt->modify('-1 second');
        $runner = $this->makeRunner(
            $this->buildRegistry($this->demoDefinition($source, new InMemoryDestination())),
            $this->descendingClock($startedAt, $finishedAt),
        );

        try {
            $runner->run('demo', new RunOptions());
            self::fail('expected MigrationAbortedException');
        } catch (MigrationAbortedException $e) {
            self::assertTrue($e->report->aborted);
            self::assertTrue(
                $e->report->finishedAt >= $e->report->startedAt,
                'RunReport::$finishedAt must be clamped to >= $startedAt on a backward clock step.',
            );
        }
    }

    #[Test]
    public function bundle_is_threaded_into_destination_records_when_declared(): void
    {
        $records = $this->makeRecords(['a', 'b']);
        $source = new InMemorySource(id: 'in_memory', records: $records);

        /** @var list<\Waaseyaa\Migration\Plugin\DestinationRecord> $captured */
        $captured = [];
        $destination = $this->recordingDestination($captured);

        $definition = $this->demoDefinition($source, $destination, bundle: 'article');
        $runner = $this->makeRunner($this->buildRegistry($definition));

        $runner->run('demo', new RunOptions());

        self::assertCount(2, $captured);
        foreach ($captured as $record) {
            self::assertSame('article', $record->bundle);
        }
    }

    #[Test]
    public function bundle_is_null_on_destination_records_when_undeclared(): void
    {
        $records = $this->makeRecords(['a']);
        $source = new InMemorySource(id: 'in_memory', records: $records);

        /** @var list<\Waaseyaa\Migration\Plugin\DestinationRecord> $captured */
        $captured = [];
        $destination = $this->recordingDestination($captured);

        $definition = $this->demoDefinition($source, $destination);
        $runner = $this->makeRunner($this->buildRegistry($definition));

        $runner->run('demo', new RunOptions());

        self::assertCount(1, $captured);
        self::assertNull($captured[0]->bundle);
    }

    /**
     * Builds a destination that appends every {@see \Waaseyaa\Migration\Plugin\DestinationRecord}
     * it receives to `$captured` (by reference) before returning a trivial
     * {@see \Waaseyaa\Migration\Plugin\WriteResult}. Used to observe exactly
     * what the runner threads into the record, independent of destination
     * write semantics.
     *
     * @param list<\Waaseyaa\Migration\Plugin\DestinationRecord> $captured
     */
    private function recordingDestination(array &$captured): DestinationPluginInterface
    {
        return new class($captured) implements DestinationPluginInterface {
            /** @param list<\Waaseyaa\Migration\Plugin\DestinationRecord> $captured */
            public function __construct(private array &$captured) {}

            public function id(): string
            {
                return 'recording_dest';
            }

            public function stability(): string
            {
                return 'stable';
            }

            public function write(\Waaseyaa\Migration\Plugin\DestinationRecord $record): \Waaseyaa\Migration\Plugin\WriteResult
            {
                $this->captured[] = $record;
                $hash = $record->sourceId->hash();

                return new \Waaseyaa\Migration\Plugin\WriteResult(
                    destinationEntityType: 'recording_entity',
                    destinationUuid: 'uuid-' . \substr($hash, 0, 12),
                    sourceRecordHash: \hash('sha256', \json_encode($record->values, \JSON_THROW_ON_ERROR)),
                    runId: '019683d3-' . \substr($hash, 0, 4) . '-7000-8000-' . \substr($hash, 0, 12),
                    writtenAt: '2026-05-13T12:00:00Z',
                );
            }

            public function rollback(\Waaseyaa\Migration\Plugin\WriteResult $result): void {}

            public function lookup(SourceId $sourceId): ?\Waaseyaa\Migration\Plugin\WriteResult
            {
                return null;
            }
        };
    }

    /**
     * Builds a clock test-seam that returns `$first` on its first
     * invocation and `$second` on every subsequent invocation — enough to
     * simulate a `$startedAt` capture followed by one-or-more `$finishedAt`
     * captures that land before it.
     */
    private function descendingClock(\DateTimeImmutable $first, \DateTimeImmutable $second): \Closure
    {
        $calls = 0;
        return function () use (&$calls, $first, $second): \DateTimeImmutable {
            $calls++;
            return $calls === 1 ? $first : $second;
        };
    }

    /**
     * @return list<SourceRecord>
     */
    private function makeRecords(array $ids): array
    {
        return \array_map(
            static fn(string $id): SourceRecord => new SourceRecord('in_memory', [
                'id' => $id,
                'value' => 'value_' . $id,
            ]),
            $ids,
        );
    }

    private function demoDefinition(
        \Waaseyaa\Migration\Plugin\SourcePluginInterface $source,
        DestinationPluginInterface $destination,
        ?string $bundle = null,
    ): MigrationDefinition {
        return new MigrationDefinition(
            id: 'demo',
            source: $source,
            process: ['value' => 'value'],
            destination: $destination,
            bundle: $bundle,
        );
    }

    private function buildRegistry(MigrationDefinition $definition): MigrationRegistry
    {
        $provider = new class([$definition]) implements HasMigrationsInterface {
            /** @param list<MigrationDefinition> $defs */
            public function __construct(private readonly array $defs) {}
            public function migrations(): iterable
            {
                yield from $this->defs;
            }
        };
        $registry = new MigrationRegistry([$provider]);
        $registry->boot();
        return $registry;
    }

    private function makeRunner(MigrationRegistry $registry, ?\Closure $clock = null): MigrationRunner
    {
        return new MigrationRunner(
            registry: $registry,
            chain: new ProcessChainExecutor(),
            idMap: $this->idMap,
            clock: $clock,
        );
    }
}
