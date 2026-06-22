<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Import;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Command\HandlerArgument;
use Waaseyaa\CLI\Command\HandlerArgumentMode;
use Waaseyaa\CLI\Command\Import\ImportRollbackCommand;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Migration\Discovery\HasMigrationsInterface;
use Waaseyaa\Migration\Discovery\MigrationRegistry;
use Waaseyaa\Migration\MigrationDefinition;
use Waaseyaa\Migration\MigrationIdMap;
use Waaseyaa\Migration\Plugin\DestinationPluginInterface;
use Waaseyaa\Migration\Plugin\DestinationRecord;
use Waaseyaa\Migration\Plugin\SourceRecord;
use Waaseyaa\Migration\Plugin\WriteResult;
use Waaseyaa\Migration\PluginFixtures\InMemorySource;
use Waaseyaa\Migration\Runner\MigrationLock;
use Waaseyaa\Migration\Runner\RollbackWalker;
use Waaseyaa\Migration\Schema\MigrationIdMapSchema;
use Waaseyaa\Migration\SourceId;

#[CoversClass(ImportRollbackCommand::class)]
final class ImportRollbackCommandTest extends TestCase
{
    #[Test]
    public function without_confirm_prints_warning_and_exits_zero(): void
    {
        [$tester, $idMap] = $this->makeRig();
        $this->seedIdMap($idMap, 3);

        $tester->execute(['demo']);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('WARNING', $tester->getStdout());
        self::assertStringContainsString('--confirm', $tester->getStdout());
        // Did not actually delete anything.
        self::assertSame(3, $idMap->countForMigration('demo'));
    }

    #[Test]
    public function unknown_migration_exits_two(): void
    {
        [$tester] = $this->makeRig();

        $tester->execute(['nonexistent']);

        self::assertSame(2, $tester->getExitCode());
        self::assertStringContainsString('unknown migration', $tester->getStderr());
    }

    #[Test]
    public function confirm_flag_runs_rollback_and_prints_summary(): void
    {
        [$tester, $idMap] = $this->makeRig();
        $this->seedIdMap($idMap, 3);

        $tester->execute(['demo', '--confirm']);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('demo: rollback complete (3/3, 0 failed)', $tester->getStdout());
        // Walker successfully deleted every id-map row.
        self::assertSame(0, $idMap->countForMigration('demo'));
    }

    #[Test]
    public function per_record_failures_surface_in_error_table_and_exit_one(): void
    {
        [$tester, $idMap] = $this->makeRig(failOnCall: 2);
        $this->seedIdMap($idMap, 3);

        $tester->execute(['demo', '--confirm']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('1 failed', $tester->getStdout());
        self::assertStringContainsString('Errors:', $tester->getStdout());
    }

    /**
     * @return array{0: CliTester, 1: MigrationIdMap}
     */
    private function makeRig(?int $failOnCall = null): array
    {
        $database = DBALDatabase::createSqlite();
        $database->getConnection()->executeStatement(MigrationIdMapSchema::createTableSql());
        foreach (MigrationIdMapSchema::createIndexSqls() as $sql) {
            $database->getConnection()->executeStatement($sql);
        }
        $idMap = new MigrationIdMap($database);

        $destination = new RollbackStubDestination($failOnCall);
        $definition = new MigrationDefinition(
            id: 'demo',
            source: new InMemorySource(id: 'in_memory', records: [new SourceRecord('fake', ['n' => 0])]),
            process: ['value' => 'value'],
            destination: $destination,
        );
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

        $walker = new RollbackWalker(registry: $registry, idMap: $idMap);

        $command = new ImportRollbackCommand($walker, $registry, $idMap, self::makeLockFactory());

        return [
            CliTester::for($this->commandDefinition(), $this->makeContainer($command)),
            $idMap,
        ];
    }

    private function seedIdMap(MigrationIdMap $idMap, int $count): void
    {
        $base = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        for ($i = 0; $i < $count; $i++) {
            $idMap->upsert(
                migrationId: 'demo',
                sourceId: new SourceId('fake', ['n' => $i]),
                destinationEntityType: 'fake_entity',
                destinationUuid: \sprintf('00000000-0000-7000-8000-%012d', $i),
                sourceRecordHash: \str_repeat((string) $i, 64),
                runId: '00000000-0000-7000-8000-000000000001',
                now: $base->modify(\sprintf('+%d seconds', $i)),
            );
        }
    }

    private function commandDefinition(): HandlerCommand
    {
        return new HandlerCommand(
            name: 'import:rollback',
            description: 'Undo every previously-written record for one migration (FR-035).',
            arguments: [
                new HandlerArgument(name: 'migration_id', mode: HandlerArgumentMode::Required),
            ],
            options: [
                new HandlerOption(name: 'confirm', mode: HandlerOptionMode::None),
            ],
            handler: [ImportRollbackCommand::class, 'execute'],
        );
    }

    private function makeContainer(ImportRollbackCommand $command): ContainerInterface
    {
        return new class($command) implements ContainerInterface {
            public function __construct(private readonly ImportRollbackCommand $command) {}
            public function get(string $id): mixed
            {
                if ($id === ImportRollbackCommand::class) {
                    return $this->command;
                }
                throw new \RuntimeException("Not bound: {$id}");
            }
            public function has(string $id): bool
            {
                return $id === ImportRollbackCommand::class;
            }
        };
    }

    /**
     * @return \Closure(string): MigrationLock
     */
    private static function makeLockFactory(): \Closure
    {
        $lockDir = \sys_get_temp_dir()
            . \DIRECTORY_SEPARATOR
            . 'waaseyaa_test_lock_'
            . \uniqid('', true);
        return static fn(string $migrationId): MigrationLock => new MigrationLock(
            migrationId: $migrationId,
            lockDir: $lockDir,
        );
    }
}

/**
 * Internal test double — a destination plugin whose `rollback()` succeeds
 * or fails based on a configured 1-indexed call number.
 *
 * @internal
 */
final class RollbackStubDestination implements DestinationPluginInterface
{
    private int $calls = 0;

    public function __construct(private readonly ?int $failOnCall = null)
    {
    }

    public function id(): string
    {
        return 'rollback_stub';
    }

    public function stability(): string
    {
        return 'experimental';
    }

    public function write(DestinationRecord $record): WriteResult
    {
        throw new \LogicException('RollbackStubDestination::write() not used.');
    }

    public function rollback(WriteResult $result): void
    {
        $this->calls++;
        if ($this->failOnCall === $this->calls) {
            throw new \RuntimeException('Stubbed failure.');
        }
    }

    public function lookup(SourceId $sourceId): ?WriteResult
    {
        return null;
    }
}
