<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Audit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditQuery;
use Waaseyaa\Audit\Contract\AuditQueryInterface;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Entity\AuditCheckpoint;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Audit\Integrity\AuditCheckpointBuilder;
use Waaseyaa\Audit\Integrity\CheckpointSink;
use Waaseyaa\Audit\Query\AuditEventQuery;
use Waaseyaa\Audit\Schema\AuditEventSchemaHandler;
use Waaseyaa\CLI\Command\Audit\PruneCommand;
use Waaseyaa\CLI\Testing\CapturingSymfonyCommandIO;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\DeleteInterface;
use Waaseyaa\Database\InsertInterface;
use Waaseyaa\Database\SchemaInterface;
use Waaseyaa\Database\SelectInterface;
use Waaseyaa\Database\TransactionInterface;
use Waaseyaa\Database\UpdateInterface;

#[CoversClass(PruneCommand::class)]
final class PruneCommandTest extends TestCase
{
    /**
     * @param array<string, mixed> $options
     */
    private function makeIo(array $options = []): CapturingSymfonyCommandIO
    {
        return new CapturingSymfonyCommandIO($options);
    }

    private function makeNullQuery(int $count = 5): AuditQueryInterface
    {
        return new class ($count) implements AuditQueryInterface {
            public function __construct(private readonly int $cnt) {}

            public function findBy(AuditQuery $query): iterable
            {
                return [];
            }

            public function count(AuditQuery $query): int
            {
                return $this->cnt;
            }
        };
    }

    private function makeNullWriter(): AuditWriterInterface
    {
        return new class implements AuditWriterInterface {
            /** @var AuditEventDescriptor[] */
            public array $recorded = [];

            public function record(AuditEventDescriptor $descriptor): void
            {
                $this->recorded[] = $descriptor;
            }
        };
    }

    /**
     * Build a SelectInterface stub that always returns an empty traversable.
     * All fluent methods return `$this`.
     */
    private function makeEmptySelect(): SelectInterface
    {
        return new class implements SelectInterface {
            public function fields(string $tableAlias, array $fields = []): static
            {
                return $this;
            }

            public function addField(string $tableAlias, string $field, string $alias = ''): static
            {
                return $this;
            }

            public function condition(string $field, mixed $value, string $operator = '='): static
            {
                return $this;
            }

            public function isNull(string $field): static
            {
                return $this;
            }

            public function isNotNull(string $field): static
            {
                return $this;
            }

            public function orderBy(string $field, string $direction = 'ASC'): static
            {
                return $this;
            }

            public function whereRaw(string $expression, array $parameters = []): static
            {
                return $this;
            }

            public function orderByRaw(string $expression, string $direction): static
            {
                return $this;
            }

            public function range(int $offset, int $limit): static
            {
                return $this;
            }

            public function join(string $table, string $alias, string $condition): static
            {
                return $this;
            }

            public function leftJoin(string $table, string $alias, string $condition): static
            {
                return $this;
            }

            public function countQuery(): static
            {
                return $this;
            }

            public function execute(): \Traversable
            {
                return new \EmptyIterator();
            }
        };
    }

    private function makeNoopDelete(): DeleteInterface
    {
        return new class implements DeleteInterface {
            public function condition(string $field, mixed $value, string $operator = '='): static
            {
                return $this;
            }

            public function execute(): int
            {
                return 0;
            }
        };
    }

    private function makeNoopUpdate(): UpdateInterface
    {
        return new class implements UpdateInterface {
            public function fields(array $fields): static
            {
                return $this;
            }

            public function condition(string $field, mixed $value, string $operator = '='): static
            {
                return $this;
            }

            public function execute(): int
            {
                return 0;
            }
        };
    }

    /**
     * A DatabaseInterface stub where:
     *  - select() returns an empty traversable (no checkpoints, no rows).
     *  - delete() returns a no-op DeleteInterface.
     *  - update() returns a no-op UpdateInterface.
     *
     * This means PruneCommand treats all events as unsealed tail (no checkpoints
     * exist) — so the legacy tail-deletion path runs, which is exactly the
     * behaviour tested by the existing unit tests.
     */
    private function makeNullDb(): DatabaseInterface
    {
        return $this->buildDb($this->makeEmptySelect(), $this->makeNoopDelete(), $this->makeNoopUpdate());
    }

    /**
     * A DatabaseInterface stub whose select() differentiates by table:
     *  - `audit_checkpoint` queries return no rows (no sealed segments, so the
     *    computed horizon stays 0).
     *  - `audit_event` queries return `$eventRowCount` dummy rows (all counted
     *    as unsealed tail by countUnsealedTailRows(), since no checkpoints
     *    exist to seal anything).
     *
     * Used so pre-existing generic self-audit/confirm-gate tests keep a
     * meaningful, non-zero deleted_count after WP F10 tied deleted_count and
     * the confirm message to the real sealed+unsealed total instead of an
     * arbitrary AuditQuery mock value (audit A7, F10).
     */
    private function makeUnsealedTailDb(int $eventRowCount, DeleteInterface $delete): DatabaseInterface
    {
        $eventRows = [];
        for ($i = 1; $i <= $eventRowCount; $i++) {
            $eventRows[] = ['id' => $i];
        }

        $eventSelect = new class ($eventRows) implements SelectInterface {
            /** @param list<array<string, mixed>> $rows */
            public function __construct(private readonly array $rows) {}

            public function fields(string $tableAlias, array $fields = []): static
            {
                return $this;
            }

            public function addField(string $tableAlias, string $field, string $alias = ''): static
            {
                return $this;
            }

            public function condition(string $field, mixed $value, string $operator = '='): static
            {
                return $this;
            }

            public function isNull(string $field): static
            {
                return $this;
            }

            public function isNotNull(string $field): static
            {
                return $this;
            }

            public function orderBy(string $field, string $direction = 'ASC'): static
            {
                return $this;
            }

            public function whereRaw(string $expression, array $parameters = []): static
            {
                return $this;
            }

            public function orderByRaw(string $expression, string $direction): static
            {
                return $this;
            }

            public function range(int $offset, int $limit): static
            {
                return $this;
            }

            public function join(string $table, string $alias, string $condition): static
            {
                return $this;
            }

            public function leftJoin(string $table, string $alias, string $condition): static
            {
                return $this;
            }

            public function countQuery(): static
            {
                return $this;
            }

            public function execute(): \Traversable
            {
                return new \ArrayIterator($this->rows);
            }
        };

        return new class ($this->makeEmptySelect(), $eventSelect, $delete, $this->makeNoopUpdate()) implements DatabaseInterface {
            public function __construct(
                private readonly SelectInterface $checkpointSelect,
                private readonly SelectInterface $eventSelect,
                private readonly DeleteInterface $del,
                private readonly UpdateInterface $upd,
            ) {}

            public function select(string $table, string $alias = ''): SelectInterface
            {
                return $table === 'audit_event' ? $this->eventSelect : $this->checkpointSelect;
            }

            public function delete(string $table): DeleteInterface
            {
                return $this->del;
            }

            public function insert(string $table): InsertInterface
            {
                throw new \LogicException('Not needed in this test.');
            }

            public function update(string $table): UpdateInterface
            {
                return $this->upd;
            }

            public function schema(): SchemaInterface
            {
                throw new \LogicException('Not needed in this test.');
            }

            public function transaction(string $name = ''): TransactionInterface
            {
                throw new \LogicException('Not needed in this test.');
            }

            public function query(string $sql, array $args = []): \Traversable
            {
                throw new \LogicException('Not needed in this test.');
            }

            public function quoteIdentifier(string $identifier): string
            {
                return '"' . $identifier . '"';
            }
        };
    }

    // ------------------------------------------------------------------
    // Real-DB helpers (audit A7, F10): seed actual sealed/unsealed rows of
    // mixed kinds so the sealed-vs-kind-filtered divergence is exercised
    // against real data rather than arbitrary mocks.
    // ------------------------------------------------------------------

    private function makeSealedRealDb(): DBALDatabase
    {
        $db = DBALDatabase::createSqlite();
        new AuditEventSchemaHandler($db)->ensureSchema();

        return $db;
    }

    private function insertRealEvent(DBALDatabase $db, string $uuid, string $eventKind, string $createdAt): void
    {
        $db->insert('audit_event')->values([
            'uuid'           => $uuid,
            'event_kind'     => $eventKind,
            'account_uid'    => 1,
            'actor_uid'      => 1,
            'entity_type_id' => 'node',
            'entity_uuid'    => 'eeeeeeee-0000-0000-0000-000000000001',
            'subject_uri'    => '/entities/node/test',
            'outcome'        => 'allowed',
            'severity'       => 'info',
            'attributes'     => '{}',
            'created_at'     => $createdAt,
        ])->execute();
    }

    private function sealRealDb(DBALDatabase $db): void
    {
        $sink = new class implements CheckpointSink {
            public function export(AuditCheckpoint $checkpoint): void {}
        };
        new AuditCheckpointBuilder($db, $sink)->build();
    }

    private function countRealEventRows(DBALDatabase $db): int
    {
        return count(iterator_to_array(
            $db->select('audit_event')->fields('audit_event', ['id'])->execute(),
            false,
        ));
    }

    /**
     * Seeds 3 `entity.write` + 2 `entity.read` events, all created 2 days
     * ago, sealed into a single checkpoint covering all 5 rows.
     */
    private function seedMixedKindSealedSegment(DBALDatabase $db): void
    {
        $past = new \DateTimeImmutable('-2 days')->format('Y-m-d H:i:s');
        $this->insertRealEvent($db, 'f10-write-1', 'entity.write', $past);
        $this->insertRealEvent($db, 'f10-write-2', 'entity.write', $past);
        $this->insertRealEvent($db, 'f10-write-3', 'entity.write', $past);
        $this->insertRealEvent($db, 'f10-read-1', 'entity.read', $past);
        $this->insertRealEvent($db, 'f10-read-2', 'entity.read', $past);
        $this->sealRealDb($db);
    }

    private function buildDb(
        SelectInterface $select,
        DeleteInterface $delete,
        UpdateInterface $update,
    ): DatabaseInterface {
        return new class ($select, $delete, $update) implements DatabaseInterface {
            public function __construct(
                private readonly SelectInterface $sel,
                private readonly DeleteInterface $del,
                private readonly UpdateInterface $upd,
            ) {}

            public function delete(string $table): DeleteInterface
            {
                return $this->del;
            }

            public function select(string $table, string $alias = ''): SelectInterface
            {
                return $this->sel;
            }

            public function insert(string $table): InsertInterface
            {
                throw new \LogicException('Not needed in this test.');
            }

            public function update(string $table): UpdateInterface
            {
                return $this->upd;
            }

            public function schema(): SchemaInterface
            {
                throw new \LogicException('Not needed in this test.');
            }

            public function transaction(string $name = ''): TransactionInterface
            {
                throw new \LogicException('Not needed in this test.');
            }

            public function query(string $sql, array $args = []): \Traversable
            {
                throw new \LogicException('Not needed in this test.');
            }

            public function quoteIdentifier(string $identifier): string
            {
                return '"' . $identifier . '"';
            }
        };
    }

    #[Test]
    public function missingOlderThanOptionReturnsError(): void
    {
        $io = $this->makeIo([]);
        $command = new PruneCommand($this->makeNullQuery(), $this->makeNullWriter(), $this->makeNullDb());

        $exitCode = $command->execute($io);

        self::assertSame(1, $exitCode);
        self::assertNotEmpty($io->errorLines());
    }

    #[Test]
    public function invalidDurationReturnsError(): void
    {
        $io = $this->makeIo(['older-than' => 'NOT_A_DURATION']);
        $command = new PruneCommand($this->makeNullQuery(), $this->makeNullWriter(), $this->makeNullDb());

        $exitCode = $command->execute($io);

        self::assertSame(1, $exitCode);
        self::assertNotEmpty($io->errorLines());
    }

    #[Test]
    public function dryRunPrintsCountWithoutDeleting(): void
    {
        $writer = $this->makeNullWriter();
        $io = $this->makeIo(['older-than' => 'P30D', 'dry-run' => true]);
        $command = new PruneCommand($this->makeNullQuery(12), $writer, $this->makeNullDb());

        $exitCode = $command->execute($io);

        self::assertSame(0, $exitCode);
        self::assertEmpty($writer->recorded, 'dry-run must not record self-audit event');
        self::assertCount(1, $io->outputLines());
        self::assertStringContainsString('dry-run', $io->outputLines()[0]);
    }

    #[Test]
    public function realRunRecordsSelfAuditAndPrints(): void
    {
        // No checkpoints and 6 unsealed-tail rows, so the real sealed+unsealed
        // total (0 + 6) coincides with the legacy kind-filtered mock (6) here:
        // kind is left at its '*' default, so nothing is filtered out.
        $writer = $this->makeNullWriter();
        $io = $this->makeIo(['older-than' => 'PT1H', 'confirm' => true]);
        $db = $this->makeUnsealedTailDb(6, $this->makeNoopDelete());
        $command = new PruneCommand($this->makeNullQuery(6), $writer, $db);

        $exitCode = $command->execute($io);

        self::assertSame(0, $exitCode);
        self::assertCount(1, $writer->recorded, 'self-audit event must be recorded exactly once');
        self::assertSame(AuditEventKind::AuditRetentionPruned, $writer->recorded[0]->kind);
        self::assertSame(6, $writer->recorded[0]->attributes['deleted_count']);
        self::assertStringContainsString('6', $io->outputLines()[0]);
    }

    #[Test]
    public function realRunWithoutConfirmDeletesNothingAndWarns(): void
    {
        // C-31 regression: a non-dry-run invocation WITHOUT --confirm must not
        // delete or record a self-audit event, and must echo the cutoff + count.
        $writer = $this->makeNullWriter();
        $delete = new class implements DeleteInterface {
            public int $executeCalls = 0;

            public function condition(string $field, mixed $value, string $operator = '='): static
            {
                return $this;
            }

            public function execute(): int
            {
                $this->executeCalls++;

                return 0;
            }
        };
        // No checkpoints and 9 unsealed-tail rows, matching the legacy mock
        // count (9) since kind='*' filters nothing out.
        $db = $this->makeUnsealedTailDb(9, $delete);

        // older-than P30D, no dry-run, no confirm.
        $io = $this->makeIo(['older-than' => 'P30D', 'kind' => '*']);
        $command = new PruneCommand($this->makeNullQuery(9), $writer, $db);

        $exitCode = $command->execute($io);

        self::assertSame(0, $exitCode, 'refusal path exits 0 (operator-driven op)');
        self::assertSame(0, $delete->executeCalls, 'no DELETE may run without --confirm');
        self::assertEmpty($writer->recorded, 'no self-audit event without --confirm');
        $output = implode("\n", $io->outputLines());
        self::assertStringContainsString('--confirm', $output, 'must instruct operator to re-run with --confirm');
        self::assertStringContainsString('9', $output, 'must echo the row count it would delete');
    }

    #[Test]
    public function realRunWithConfirmDeletesAndRecordsSelfAudit(): void
    {
        // C-31 regression (positive case): --confirm proceeds with deletion.
        $writer = $this->makeNullWriter();
        $delete = new class implements DeleteInterface {
            public int $executeCalls = 0;

            public function condition(string $field, mixed $value, string $operator = '='): static
            {
                return $this;
            }

            public function execute(): int
            {
                $this->executeCalls++;

                return 0;
            }
        };
        $db = $this->buildDb($this->makeEmptySelect(), $delete, $this->makeNoopUpdate());

        $io = $this->makeIo(['older-than' => 'P30D', 'kind' => '*', 'confirm' => true]);
        $command = new PruneCommand($this->makeNullQuery(9), $writer, $db);

        $exitCode = $command->execute($io);

        self::assertSame(0, $exitCode);
        // With no checkpoints (empty select), horizon=0, only tail path runs.
        // Tail DELETE is called once (path B).
        self::assertSame(1, $delete->executeCalls, '--confirm must trigger exactly one DELETE (tail path)');
        self::assertCount(1, $writer->recorded, '--confirm must record the self-audit event');
        self::assertSame(AuditEventKind::AuditRetentionPruned, $writer->recorded[0]->kind);
    }

    #[Test]
    public function kindPatternWildcardMatchesAllKinds(): void
    {
        $capturedQuery = null;
        $query = new class ($capturedQuery) implements AuditQueryInterface {
            public ?AuditQuery $capturedQuery = null;

            public function findBy(AuditQuery $q): iterable
            {
                return [];
            }

            public function count(AuditQuery $q): int
            {
                $this->capturedQuery = $q;

                return 0;
            }
        };

        $io = $this->makeIo(['older-than' => 'P1D', 'kind' => '*']);
        $command = new PruneCommand($query, $this->makeNullWriter(), $this->makeNullDb());
        $command->execute($io);

        self::assertNotNull($query->capturedQuery);
        // * matches all AuditEventKind cases.
        self::assertCount(count(AuditEventKind::cases()), $query->capturedQuery->kinds);
    }

    #[Test]
    public function kindPatternPrefixFiltersMatchingCases(): void
    {
        $capturedQuery = null;
        $query = new class ($capturedQuery) implements AuditQueryInterface {
            public ?AuditQuery $capturedQuery = null;

            public function findBy(AuditQuery $q): iterable
            {
                return [];
            }

            public function count(AuditQuery $q): int
            {
                $this->capturedQuery = $q;

                return 0;
            }
        };

        $io = $this->makeIo(['older-than' => 'P1D', 'kind' => 'entity.*']);
        $command = new PruneCommand($query, $this->makeNullWriter(), $this->makeNullDb());
        $command->execute($io);

        self::assertNotNull($query->capturedQuery);
        self::assertNotNull($query->capturedQuery->kinds);
        foreach ($query->capturedQuery->kinds as $kind) {
            self::assertStringStartsWith('entity.', $kind->value);
        }
    }

    #[Test]
    public function selfAuditAttributesIncludeWp4Fields(): void
    {
        // Verify the self-audit event carries the WP4-specific attributes.
        $writer = $this->makeNullWriter();
        $io = $this->makeIo(['older-than' => 'PT1H', 'confirm' => true]);
        $command = new PruneCommand($this->makeNullQuery(0), $writer, $this->makeNullDb());

        $exitCode = $command->execute($io);

        self::assertSame(0, $exitCode);
        self::assertCount(1, $writer->recorded);
        $attrs = $writer->recorded[0]->attributes;
        self::assertArrayHasKey('sealed_pruned_through_id', $attrs);
        self::assertArrayHasKey('pruned_checkpoint_hash', $attrs);
        self::assertArrayHasKey('unsealed_deleted_count', $attrs);
        // With no checkpoints the sealed horizon is 0 and hash is empty.
        self::assertSame(0, $attrs['sealed_pruned_through_id']);
        self::assertSame('', $attrs['pruned_checkpoint_hash']);
    }

    // ------------------------------------------------------------------
    // Audit A7, F10: self-audit deleted_count must reflect the real
    // sealed+unsealed total, not a kind-filtered match count.
    // ------------------------------------------------------------------

    #[Test]
    public function selfAuditDeletedCountReflectsRealTotalNotKindFilteredMatch(): void
    {
        // Sealed segments are pruned WHOLE regardless of --kind (chain
        // integrity, Path A). Seed 3 entity.write + 2 entity.read events
        // sealed into one segment; --kind=entity.write only matches 3 of
        // them, but Path A actually deletes all 5.
        $db = $this->makeSealedRealDb();
        $this->seedMixedKindSealedSegment($db);
        self::assertSame(5, $this->countRealEventRows($db), 'sanity: 5 rows seeded');

        $writer = $this->makeNullWriter();
        $auditQuery = new AuditEventQuery($db);
        $io = $this->makeIo(['older-than' => 'P1D', 'kind' => 'entity.write', 'confirm' => true]);
        $command = new PruneCommand($auditQuery, $writer, $db);

        $exitCode = $command->execute($io);

        self::assertSame(0, $exitCode);
        self::assertCount(1, $writer->recorded);
        self::assertSame(
            0,
            $this->countRealEventRows($db),
            'sanity: Path A deletes the whole sealed segment (all 5 rows), kind-agnostic by design',
        );
        self::assertSame(
            5,
            $writer->recorded[0]->attributes['deleted_count'],
            'deleted_count must equal the ACTUAL rows deleted (5), not the kind-filtered match count (3)',
        );
        self::assertSame(
            3,
            $writer->recorded[0]->attributes['kind_filtered_match_count'],
            'the legacy kind-filtered number must be preserved under kind_filtered_match_count',
        );
    }

    #[Test]
    public function selfAuditDeletedCountSumsSealedAndUnsealedWithSurvivors(): void
    {
        // End-to-end exercise of realTotal = sealedCount + unsealedCount with
        // BOTH operands nonzero plus survivors: 5 sealed mixed-kind rows
        // (3 entity.write, 2 entity.read) plus an unsealed tail of 3 old
        // entity.write and 2 old entity.read rows. With --kind=entity.write:
        //  - Path A deletes all 5 sealed rows (kind-agnostic, chain integrity),
        //  - Path B deletes only the 3 kind-matching unsealed-tail rows,
        //  - the 2 entity.read tail rows survive.
        $db = $this->makeSealedRealDb();
        $this->seedMixedKindSealedSegment($db);

        $past = new \DateTimeImmutable('-2 days')->format('Y-m-d H:i:s');
        $this->insertRealEvent($db, 'f10-tail-write-1', 'entity.write', $past);
        $this->insertRealEvent($db, 'f10-tail-write-2', 'entity.write', $past);
        $this->insertRealEvent($db, 'f10-tail-write-3', 'entity.write', $past);
        $this->insertRealEvent($db, 'f10-tail-read-1', 'entity.read', $past);
        $this->insertRealEvent($db, 'f10-tail-read-2', 'entity.read', $past);
        self::assertSame(10, $this->countRealEventRows($db), 'sanity: 5 sealed + 5 unsealed rows seeded');

        $writer = $this->makeNullWriter();
        $auditQuery = new AuditEventQuery($db);
        $io = $this->makeIo(['older-than' => 'P1D', 'kind' => 'entity.write', 'confirm' => true]);
        $command = new PruneCommand($auditQuery, $writer, $db);

        $exitCode = $command->execute($io);

        self::assertSame(0, $exitCode);
        self::assertCount(1, $writer->recorded);
        self::assertSame(
            2,
            $this->countRealEventRows($db),
            'the 2 kind-mismatched unsealed-tail rows must survive',
        );
        self::assertSame(
            8,
            $writer->recorded[0]->attributes['deleted_count'],
            'deleted_count must be 8: 5 sealed (all kinds) + 3 unsealed kind-filtered',
        );
        self::assertSame(
            3,
            $writer->recorded[0]->attributes['unsealed_deleted_count'],
            'unsealed_deleted_count must be the kind-filtered tail count (3)',
        );
    }

    #[Test]
    public function confirmationRefusalReportsRealTotalNotKindFilteredMatch(): void
    {
        $db = $this->makeSealedRealDb();
        $this->seedMixedKindSealedSegment($db);

        $auditQuery = new AuditEventQuery($db);
        $io = $this->makeIo(['older-than' => 'P1D', 'kind' => 'entity.write']);
        $command = new PruneCommand($auditQuery, $this->makeNullWriter(), $db);

        $exitCode = $command->execute($io);

        self::assertSame(0, $exitCode);
        self::assertSame(5, $this->countRealEventRows($db), 'nothing may be deleted without --confirm');
        $output = implode("\n", $io->outputLines());
        self::assertStringContainsString(
            'Refusing to prune 5 audit events',
            $output,
            'refusal message must report the real total (5) that would actually be deleted',
        );
        self::assertStringContainsString(
            'sealed: 5, unsealed: 0',
            $output,
            'refusal message must include the sealed/unsealed breakdown',
        );
        self::assertStringNotContainsString(
            'Refusing to prune 3 ',
            $output,
            'refusal message must not report the stale kind-filtered match count (3)',
        );
    }

    #[Test]
    public function deletedCountUnchangedWhenKindFilterMatchesEverything(): void
    {
        // Regression lock-in: with no --kind filter, the kind-filtered count
        // and the real sealed+unsealed total coincide, so this fix must not
        // change the reported number in that case.
        $db = $this->makeSealedRealDb();
        $this->seedMixedKindSealedSegment($db);

        $auditQuery = new AuditEventQuery($db);
        $writer = $this->makeNullWriter();
        $io = $this->makeIo(['older-than' => 'P1D', 'kind' => '*', 'confirm' => true]);
        $command = new PruneCommand($auditQuery, $writer, $db);

        $exitCode = $command->execute($io);

        self::assertSame(0, $exitCode);
        self::assertCount(1, $writer->recorded);
        self::assertSame(5, $writer->recorded[0]->attributes['deleted_count']);
        self::assertSame(
            5,
            $writer->recorded[0]->attributes['kind_filtered_match_count'],
            'with kind=* the kind-filtered count coincides with the real total',
        );
        self::assertSame(0, $this->countRealEventRows($db));
    }

    #[Test]
    public function dryRunBreakdownUnaffectedByKindFilterMismatch(): void
    {
        // Lock in the current (already-correct) dry-run breakdown: it must
        // report the accurate sealed-segment count regardless of --kind,
        // matching the fixed self-audit/confirm-prompt behaviour.
        $db = $this->makeSealedRealDb();
        $this->seedMixedKindSealedSegment($db);

        $auditQuery = new AuditEventQuery($db);
        $writer = $this->makeNullWriter();
        $io = $this->makeIo(['older-than' => 'P1D', 'kind' => 'entity.write', 'dry-run' => true]);
        $command = new PruneCommand($auditQuery, $writer, $db);

        $exitCode = $command->execute($io);

        self::assertSame(0, $exitCode);
        self::assertEmpty($writer->recorded, 'dry-run must not record self-audit event');
        self::assertSame(5, $this->countRealEventRows($db), 'dry-run must not delete anything');
        $output = implode("\n", $io->outputLines());
        self::assertStringContainsString('Would prune 5 sealed event row(s)', $output);
        self::assertStringContainsString('0 unsealed tail row(s)', $output);
    }
}
