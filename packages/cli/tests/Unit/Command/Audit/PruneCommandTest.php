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
use Waaseyaa\CLI\CliIO;
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
    private function makeIo(array $options = []): CliIO&object
    {
        return new class ($options) implements CliIO {
            /** @var string[] */
            public array $lines = [];
            /** @var string[] */
            public array $errors = [];

            /** @param array<string, mixed> $opts */
            public function __construct(private readonly array $opts) {}

            public function option(string $name): string|int|float|bool|array|null
            {
                return $this->opts[$name] ?? null;
            }

            public function argument(string $name): string|int|float|bool|array|null
            {
                return null;
            }

            /** @return array<string, scalar|array|null> */
            public function arguments(): array
            {
                return [];
            }

            /** @return array<string, scalar|array|null> */
            public function options(): array
            {
                return $this->opts;
            }

            public function write(string $text): void {}

            public function writeln(string $line): void
            {
                $this->lines[] = $line;
            }

            public function error(string $line): void
            {
                $this->errors[] = $line;
            }

            public function ask(string $question, ?string $default = null): ?string
            {
                return $default;
            }

            public function confirm(string $question, bool $default = false): bool
            {
                return $default;
            }

            public function isVerbose(): bool
            {
                return false;
            }

            public function isInteractive(): bool
            {
                return false;
            }

            /** @return string[] */
            public function outputLines(): array
            {
                return $this->lines;
            }

            /** @return string[] */
            public function errorLines(): array
            {
                return $this->errors;
            }
        };
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
        return new class () implements AuditWriterInterface {
            /** @var AuditEventDescriptor[] */
            public array $recorded = [];

            public function record(AuditEventDescriptor $descriptor): void
            {
                $this->recorded[] = $descriptor;
            }
        };
    }

    private function makeNullDb(): DatabaseInterface
    {
        $delete = new class () implements DeleteInterface {
            public function condition(string $field, mixed $value, string $operator = '='): static
            {
                return $this;
            }

            public function execute(): int
            {
                return 0;
            }
        };

        return new class ($delete) implements DatabaseInterface {
            public function __construct(private readonly DeleteInterface $del) {}

            public function delete(string $table): DeleteInterface
            {
                return $this->del;
            }

            public function select(string $table, string $alias = ''): SelectInterface
            {
                throw new \LogicException('Not needed in this test.');
            }

            public function insert(string $table): InsertInterface
            {
                throw new \LogicException('Not needed in this test.');
            }

            public function update(string $table): UpdateInterface
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
        self::assertStringContainsString('12', $io->outputLines()[0]);
        self::assertStringContainsString('dry-run', $io->outputLines()[0]);
    }

    #[Test]
    public function realRunRecordsSelfAuditAndPrints(): void
    {
        $writer = $this->makeNullWriter();
        $io = $this->makeIo(['older-than' => 'PT1H']);
        $command = new PruneCommand($this->makeNullQuery(6), $writer, $this->makeNullDb());

        $exitCode = $command->execute($io);

        self::assertSame(0, $exitCode);
        self::assertCount(1, $writer->recorded, 'self-audit event must be recorded exactly once');
        self::assertSame(AuditEventKind::AuditRetentionPruned, $writer->recorded[0]->kind);
        self::assertSame(6, $writer->recorded[0]->attributes['deleted_count']);
        self::assertStringContainsString('6', $io->outputLines()[0]);
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
}
