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
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\CLI\Command\Audit\PruneCommand;
use Waaseyaa\CLI\Testing\CapturingSymfonyCommandIO;
use Waaseyaa\Database\DatabaseInterface;
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
        $writer = $this->makeNullWriter();
        $io = $this->makeIo(['older-than' => 'PT1H', 'confirm' => true]);
        $command = new PruneCommand($this->makeNullQuery(6), $writer, $this->makeNullDb());

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
        $db = $this->buildDb($this->makeEmptySelect(), $delete, $this->makeNoopUpdate());

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
}
