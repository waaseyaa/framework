<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Audit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Audit\Integrity\AuditChainVerifier;
use Waaseyaa\Audit\Integrity\AuditCheckpointHasher;
use Waaseyaa\Audit\Integrity\AuditEventCanonicalizer;
use Waaseyaa\CLI\Command\Audit\VerifyCommand;
use Waaseyaa\CLI\Testing\CapturingSymfonyCommandIO;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DeleteInterface;
use Waaseyaa\Database\InsertInterface;
use Waaseyaa\Database\SchemaInterface;
use Waaseyaa\Database\SelectInterface;
use Waaseyaa\Database\TransactionInterface;
use Waaseyaa\Database\UpdateInterface;

#[CoversClass(VerifyCommand::class)]
final class VerifyCommandTest extends TestCase
{
    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $options */
    private function makeIo(array $options = []): CapturingSymfonyCommandIO
    {
        return new CapturingSymfonyCommandIO($options);
    }

    private function makeSpyWriter(): AuditWriterInterface
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
     * Build a minimal DatabaseInterface stub.
     *
     * @param list<array<string, mixed>> $checkpointRows
     * @param list<array<string, mixed>> $eventRows
     */
    private function makeStubDb(array $checkpointRows, array $eventRows): DatabaseInterface
    {
        return new class ($checkpointRows, $eventRows) implements DatabaseInterface {
            /** @param list<array<string, mixed>> $checkpointRows */
            /** @param list<array<string, mixed>> $eventRows */
            public function __construct(
                private readonly array $checkpointRows,
                private readonly array $eventRows,
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
                throw new \LogicException('Not needed in this test.');
            }

            public function update(string $table): UpdateInterface
            {
                throw new \LogicException('Not needed in this test.');
            }

            public function delete(string $table): DeleteInterface
            {
                throw new \LogicException('Not needed in this test.');
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

    /**
     * Verifier wired to a genesis-only DB that will return an intact result.
     */
    private function makeIntactVerifier(): AuditChainVerifier
    {
        $genesisHash = AuditCheckpointHasher::checkpointHash(
            1,
            0,
            0,
            AuditEventCanonicalizer::GENESIS_HASH,
            AuditEventCanonicalizer::GENESIS_HASH,
        );

        $genesisRow = [
            'id'                   => 1,
            'segment_start_id'     => 1,
            'segment_end_id'       => 0,
            'row_count'            => 0,
            'segment_hash'         => AuditEventCanonicalizer::GENESIS_HASH,
            'prev_checkpoint_hash' => AuditEventCanonicalizer::GENESIS_HASH,
            'checkpoint_hash'      => $genesisHash,
            'is_genesis'           => 1,
        ];

        return new AuditChainVerifier($this->makeStubDb([$genesisRow], []));
    }

    /**
     * Verifier wired to a genesis checkpoint with a corrupt hash — will return broken.
     */
    private function makeBrokenVerifier(): AuditChainVerifier
    {
        $genesisRow = [
            'id'                   => 1,
            'segment_start_id'     => 1,
            'segment_end_id'       => 0,
            'row_count'            => 0,
            'segment_hash'         => AuditEventCanonicalizer::GENESIS_HASH,
            'prev_checkpoint_hash' => AuditEventCanonicalizer::GENESIS_HASH,
            'checkpoint_hash'      => str_repeat('b', 64), // deliberately wrong
            'is_genesis'           => 1,
        ];

        return new AuditChainVerifier($this->makeStubDb([$genesisRow], []));
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    #[Test]
    public function intact_chain_exits_zero_and_records_allowed_self_audit(): void
    {
        $writer   = $this->makeSpyWriter();
        $verifier = $this->makeIntactVerifier();
        $io       = $this->makeIo();
        $command  = new VerifyCommand($verifier, $writer);

        $exitCode = $command->execute($io);

        self::assertSame(0, $exitCode);
        self::assertCount(1, $writer->recorded, 'exactly one self-audit event must be recorded');
        self::assertSame(AuditEventKind::AuditVerified, $writer->recorded[0]->kind);
        self::assertSame('allowed', $writer->recorded[0]->outcome);
        $output = implode("\n", $io->outputLines());
        self::assertStringContainsString('audit:verify OK', $output);
    }

    #[Test]
    public function broken_chain_exits_one_and_records_denied_self_audit(): void
    {
        $writer   = $this->makeSpyWriter();
        $verifier = $this->makeBrokenVerifier();
        $io       = $this->makeIo();
        $command  = new VerifyCommand($verifier, $writer);

        $exitCode = $command->execute($io);

        self::assertSame(1, $exitCode);
        self::assertCount(1, $writer->recorded, 'exactly one self-audit event must be recorded');
        self::assertSame(AuditEventKind::AuditVerified, $writer->recorded[0]->kind);
        self::assertSame('denied', $writer->recorded[0]->outcome);
        $errors = implode("\n", $io->errorLines());
        self::assertStringContainsString('TAMPER DETECTED', $errors);
    }

    #[Test]
    public function json_flag_outputs_json_object_on_intact(): void
    {
        $writer   = $this->makeSpyWriter();
        $verifier = $this->makeIntactVerifier();
        $io       = $this->makeIo(['json' => true]);
        $command  = new VerifyCommand($verifier, $writer);

        $exitCode = $command->execute($io);

        self::assertSame(0, $exitCode);
        $output = implode("\n", $io->outputLines());
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($decoded['ok']);
        self::assertArrayHasKey('segments_verified', $decoded);
        self::assertArrayHasKey('rows_verified', $decoded);
        self::assertArrayHasKey('pending_unsealed_rows', $decoded);
    }

    #[Test]
    public function json_flag_outputs_json_object_on_broken(): void
    {
        $writer   = $this->makeSpyWriter();
        $verifier = $this->makeBrokenVerifier();
        $io       = $this->makeIo(['json' => true]);
        $command  = new VerifyCommand($verifier, $writer);

        $exitCode = $command->execute($io);

        self::assertSame(1, $exitCode);
        $output = implode("\n", $io->outputLines());
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($decoded['ok']);
        self::assertNotNull($decoded['failure_kind']);
    }
}
