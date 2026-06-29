<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Audit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Entity\AuditCheckpoint;
use Waaseyaa\Audit\Integrity\AuditCheckpointBuilder;
use Waaseyaa\Audit\Integrity\AuditEventCanonicalizer;
use Waaseyaa\Audit\Integrity\CheckpointSink;
use Waaseyaa\CLI\Command\Audit\CheckpointCommand;
use Waaseyaa\CLI\Testing\CapturingSymfonyCommandIO;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DeleteInterface;
use Waaseyaa\Database\InsertInterface;
use Waaseyaa\Database\SchemaInterface;
use Waaseyaa\Database\SelectInterface;
use Waaseyaa\Database\TransactionInterface;
use Waaseyaa\Database\UpdateInterface;

#[CoversClass(CheckpointCommand::class)]
final class CheckpointCommandTest extends TestCase
{
    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $options */
    private function makeIo(array $options = []): CapturingSymfonyCommandIO
    {
        return new CapturingSymfonyCommandIO($options);
    }

    private function makeNullSink(): CheckpointSink
    {
        return new class implements CheckpointSink {
            public function export(AuditCheckpoint $checkpoint): void {}
        };
    }

    /**
     * Build a DatabaseInterface stub whose select() returns the given rows.
     *
     * @param list<array<string, mixed>> $checkpointRows Rows returned for audit_checkpoint queries.
     * @param list<array<string, mixed>> $eventRows      Rows returned for audit_event queries.
     */
    private function makeStubDb(array $checkpointRows = [], array $eventRows = []): DatabaseInterface
    {
        $insert = new class implements InsertInterface {
            public function fields(array $fields): static
            {
                return $this;
            }

            public function values(array $values): static
            {
                return $this;
            }

            public function execute(): int|string
            {
                return 1;
            }
        };

        $update = new class implements UpdateInterface {
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
                return 1;
            }
        };

        return new class ($checkpointRows, $eventRows, $insert, $update) implements DatabaseInterface {
            /** @param list<array<string, mixed>> $checkpointRows */
            /** @param list<array<string, mixed>> $eventRows */
            public function __construct(
                private readonly array $checkpointRows,
                private readonly array $eventRows,
                private readonly InsertInterface $ins,
                private readonly UpdateInterface $upd,
            ) {}

            public function select(string $table, string $alias = ''): SelectInterface
            {
                $rows = $table === 'audit_checkpoint' ? $this->checkpointRows : $this->eventRows;

                return new class ($rows) implements SelectInterface {
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
            }

            public function insert(string $table): InsertInterface
            {
                return $this->ins;
            }

            public function update(string $table): UpdateInterface
            {
                return $this->upd;
            }

            public function delete(string $table): DeleteInterface
            {
                throw new \LogicException('Not needed.');
            }

            public function schema(): SchemaInterface
            {
                throw new \LogicException('Not needed.');
            }

            public function transaction(string $name = ''): TransactionInterface
            {
                throw new \LogicException('Not needed.');
            }

            public function query(string $sql, array $args = []): \Traversable
            {
                throw new \LogicException('Not needed.');
            }

            public function quoteIdentifier(string $identifier): string
            {
                return '"' . $identifier . '"';
            }
        };
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    #[Test]
    public function testExecuteWithNoEventsOutputsNoOpMessage(): void
    {
        // Provide a genesis checkpoint so builder's find-latest query returns it,
        // but no event rows so build() returns null.
        $genesisRow = [
            'segment_end_id'    => 0,
            'segment_hash'      => AuditEventCanonicalizer::GENESIS_HASH,
            'checkpoint_hash'   => AuditEventCanonicalizer::GENESIS_HASH,
        ];
        $db      = $this->makeStubDb([$genesisRow], []);
        $builder = new AuditCheckpointBuilder($db, $this->makeNullSink());

        $io       = $this->makeIo();
        $command  = new CheckpointCommand($builder);
        $exitCode = $command->execute($io);

        self::assertSame(0, $exitCode);
        self::assertCount(1, $io->outputLines());
        self::assertStringContainsString('nothing to do', $io->outputLines()[0]);
    }

    #[Test]
    public function testExecuteWithEventsOutputsSuccessMessage(): void
    {
        // No prior checkpoints (genesis only) + one event row.
        $genesisRow = [
            'segment_end_id'    => 0,
            'segment_hash'      => AuditEventCanonicalizer::GENESIS_HASH,
            'checkpoint_hash'   => AuditEventCanonicalizer::GENESIS_HASH,
        ];
        $eventRow = [
            'id'             => 1,
            'uuid'           => 'event-uuid-0001',
            'event_kind'     => 'entity.write',
            'account_uid'    => 1,
            'actor_uid'      => 1,
            'entity_type_id' => 'node',
            'entity_uuid'    => 'eeeeeeee-0000-0000-0000-000000000001',
            'subject_uri'    => '/entities/node/test',
            'outcome'        => 'allowed',
            'severity'       => 'info',
            'attributes'     => '{}',
            'created_at'     => '2026-01-01 00:00:00',
        ];
        $db       = $this->makeStubDb([$genesisRow], [$eventRow]);
        $builder  = new AuditCheckpointBuilder($db, $this->makeNullSink());

        $io       = $this->makeIo();
        $command  = new CheckpointCommand($builder);
        $exitCode = $command->execute($io);

        self::assertSame(0, $exitCode);
        self::assertCount(1, $io->outputLines());
        $line = $io->outputLines()[0];
        self::assertStringContainsString('Sealed audit checkpoint', $line);
        self::assertStringContainsString('1', $line, 'row count in output');
    }
}
