<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Import;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Command\HandlerArgument;
use Waaseyaa\CLI\Command\HandlerArgumentMode;
use Waaseyaa\CLI\Command\Import\ImportResetCommand;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Migration\Discovery\HasMigrationsInterface;
use Waaseyaa\Migration\Discovery\MigrationRegistry;
use Waaseyaa\Migration\MigrationDefinition;
use Waaseyaa\Migration\MigrationIdMap;
use Waaseyaa\Migration\MigrationRunState;
use Waaseyaa\Migration\Plugin\SourceRecord;
use Waaseyaa\Migration\PluginFixtures\InMemoryDestination;
use Waaseyaa\Migration\PluginFixtures\InMemorySource;
use Waaseyaa\Migration\Runner\MigrationLock;
use Waaseyaa\Migration\Schema\MigrationIdMapSchema;
use Waaseyaa\Migration\Schema\MigrationRunStateSchema;
use Waaseyaa\Migration\SourceId;

#[CoversClass(ImportResetCommand::class)]
final class ImportResetCommandTest extends TestCase
{
    #[Test]
    public function without_confirm_prints_warning_and_does_not_delete(): void
    {
        [$tester, $idMap, $runState] = $this->makeRig();
        $this->seedIdMap($idMap);
        $this->seedRunState($runState);

        $tester->execute(['demo']);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('WARNING', $tester->getStdout());
        self::assertStringContainsString('NOT be touched', $tester->getStdout());
        self::assertStringContainsString('--confirm', $tester->getStdout());
        // Nothing was actually deleted.
        self::assertSame(3, $idMap->countForMigration('demo'));
        self::assertGreaterThan(0, $runState->countByStatus('demo')['success']);
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
    public function confirm_clears_id_map_and_run_state(): void
    {
        [$tester, $idMap, $runState] = $this->makeRig();
        $this->seedIdMap($idMap);
        $this->seedRunState($runState);

        $tester->execute(['demo', '--confirm']);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('reset complete', $tester->getStdout());
        self::assertStringContainsString('id-map rows', $tester->getStdout());
        self::assertSame(0, $idMap->countForMigration('demo'));
        self::assertSame(0, $runState->countByStatus('demo')['success']);
    }

    /**
     * @return array{0: CliTester, 1: MigrationIdMap, 2: MigrationRunState}
     */
    private function makeRig(): array
    {
        $database = DBALDatabase::createSqlite();
        $database->getConnection()->executeStatement(MigrationIdMapSchema::createTableSql());
        foreach (MigrationIdMapSchema::createIndexSqls() as $sql) {
            $database->getConnection()->executeStatement($sql);
        }
        $database->getConnection()->executeStatement(MigrationRunStateSchema::createTableSql());
        foreach (MigrationRunStateSchema::createIndexSqls() as $sql) {
            $database->getConnection()->executeStatement($sql);
        }

        $idMap = new MigrationIdMap($database);
        $runState = new MigrationRunState($database);

        $definition = new MigrationDefinition(
            id: 'demo',
            source: new InMemorySource(id: 'in_memory', records: [new SourceRecord('fake', ['n' => 0])]),
            process: ['value' => 'value'],
            destination: new InMemoryDestination(),
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

        $command = new ImportResetCommand($registry, $idMap, $runState, self::makeLockFactory());

        return [
            CliTester::for($this->commandDefinition(), $this->makeContainer($command)),
            $idMap,
            $runState,
        ];
    }

    private function seedIdMap(MigrationIdMap $idMap): void
    {
        $base = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        for ($i = 0; $i < 3; $i++) {
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

    private function seedRunState(MigrationRunState $runState): void
    {
        $runState->recordSuccess(
            migrationId: 'demo',
            sourceIdHash: \str_repeat('a', 64),
            runId: '00000000-0000-7000-8000-000000000001',
            position: 1,
            now: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    private function commandDefinition(): HandlerCommand
    {
        return new HandlerCommand(
            name: 'import:reset',
            description: 'Clear id-map + run-state without touching destination entities (FR-036).',
            arguments: [
                new HandlerArgument(name: 'migration_id', mode: HandlerArgumentMode::Required),
            ],
            options: [
                new HandlerOption(name: 'confirm', mode: HandlerOptionMode::None),
            ],
            handler: [ImportResetCommand::class, 'execute'],
        );
    }

    private function makeContainer(ImportResetCommand $command): ContainerInterface
    {
        return new class($command) implements ContainerInterface {
            public function __construct(private readonly ImportResetCommand $command) {}
            public function get(string $id): mixed
            {
                if ($id === ImportResetCommand::class) {
                    return $this->command;
                }
                throw new \RuntimeException("Not bound: {$id}");
            }
            public function has(string $id): bool
            {
                return $id === ImportResetCommand::class;
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
