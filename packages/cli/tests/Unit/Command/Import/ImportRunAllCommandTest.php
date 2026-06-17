<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Import;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Command\Import\ImportRunAllCommand;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Migration\Discovery\HasMigrationsInterface;
use Waaseyaa\Migration\Discovery\MigrationRegistry;
use Waaseyaa\Migration\MigrationDefinition;
use Waaseyaa\Migration\MigrationIdMap;
use Waaseyaa\Migration\Plugin\SourceRecord;
use Waaseyaa\Migration\PluginFixtures\AlwaysFailingProcessor;
use Waaseyaa\Migration\PluginFixtures\InMemoryDestination;
use Waaseyaa\Migration\PluginFixtures\InMemorySource;
use Waaseyaa\Migration\Runner\MigrationLock;
use Waaseyaa\Migration\Runner\MigrationRunner;
use Waaseyaa\Migration\Runner\ProcessChainExecutor;
use Waaseyaa\Migration\Schema\MigrationIdMapSchema;

#[CoversClass(ImportRunAllCommand::class)]
final class ImportRunAllCommandTest extends TestCase
{
    #[Test]
    public function walks_two_migrations_in_topological_order(): void
    {
        $migA = new MigrationDefinition(
            id: 'mig_a',
            source: new InMemorySource(id: 'in_memory', records: $this->makeRecords(['a1', 'a2'])),
            process: ['value' => 'value'],
            destination: new InMemoryDestination(id: 'dest_a'),
        );
        $migB = new MigrationDefinition(
            id: 'mig_b',
            source: new InMemorySource(id: 'in_memory', records: $this->makeRecords(['b1'])),
            process: ['value' => 'value'],
            destination: new InMemoryDestination(id: 'dest_b'),
            dependencies: ['mig_a'],
        );

        $tester = $this->makeTester([$migA, $migB]);
        $tester->execute([]);

        self::assertSame(0, $tester->getExitCode());
        $stdout = $tester->getStdout();

        // Topological order means mig_a comes first.
        $posA = \strpos($stdout, 'mig_a:');
        $posB = \strpos($stdout, 'mig_b:');
        self::assertNotFalse($posA);
        self::assertNotFalse($posB);
        self::assertLessThan($posB, $posA);

        // Aggregate footer.
        self::assertStringContainsString('Run-all: 2 migration(s), 3 imported, 0 skipped, 0 failed.', $stdout);
    }

    #[Test]
    public function per_record_errors_in_one_migration_do_not_halt_the_walk(): void
    {
        $failing = new MigrationDefinition(
            id: 'mig_a',
            source: new InMemorySource(id: 'in_memory', records: $this->makeRecords(['x', 'y'])),
            process: ['body' => [new AlwaysFailingProcessor()]],
            destination: new InMemoryDestination(),
        );
        $clean = new MigrationDefinition(
            id: 'mig_b',
            source: new InMemorySource(id: 'in_memory', records: $this->makeRecords(['z'])),
            process: ['value' => 'value'],
            destination: new InMemoryDestination(),
            dependencies: ['mig_a'],
        );

        $tester = $this->makeTester([$failing, $clean]);
        $tester->execute([]);

        // Worst exit is 1 (per-record errors in mig_a) — mig_b ran cleanly.
        self::assertSame(1, $tester->getExitCode());
        $stdout = $tester->getStdout();
        self::assertStringContainsString('mig_a: failed', $stdout);
        self::assertStringContainsString('mig_b: complete', $stdout);
        self::assertStringContainsString('2 migration(s), 1 imported, 0 skipped, 2 failed', $stdout);
    }

    #[Test]
    public function empty_registry_returns_zero_and_prints_message(): void
    {
        $database = DBALDatabase::createSqlite();
        $database->getConnection()->executeStatement(MigrationIdMapSchema::createTableSql());

        $idMap = new MigrationIdMap($database);
        $registry = new MigrationRegistry([]);
        $registry->boot();

        $runner = new MigrationRunner($registry, new ProcessChainExecutor(), $idMap);
        $command = new ImportRunAllCommand($runner, $registry, self::makeLockFactory());
        $tester = CliTester::for(
            $this->commandDefinition(),
            $this->makeContainer($command),
        );

        $tester->execute([]);
        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('No migrations registered.', $tester->getStdout());
    }

    /**
     * @return list<SourceRecord>
     */
    private function makeRecords(array $ids): array
    {
        return \array_map(
            static fn(string $id): SourceRecord => new SourceRecord('in_memory', ['id' => $id, 'value' => 'v_' . $id]),
            $ids,
        );
    }

    /**
     * @param list<MigrationDefinition> $definitions
     */
    private function makeTester(array $definitions): CliTester
    {
        $database = DBALDatabase::createSqlite();
        $database->getConnection()->executeStatement(MigrationIdMapSchema::createTableSql());
        foreach (MigrationIdMapSchema::createIndexSqls() as $sql) {
            $database->getConnection()->executeStatement($sql);
        }
        $idMap = new MigrationIdMap($database);

        $provider = new class($definitions) implements HasMigrationsInterface {
            /** @param list<MigrationDefinition> $defs */
            public function __construct(private readonly array $defs) {}
            public function migrations(): iterable
            {
                yield from $this->defs;
            }
        };
        $registry = new MigrationRegistry([$provider]);
        $registry->boot();

        $runner = new MigrationRunner($registry, new ProcessChainExecutor(), $idMap);
        $command = new ImportRunAllCommand($runner, $registry, self::makeLockFactory());
        return CliTester::for($this->commandDefinition(), $this->makeContainer($command));
    }

    private function commandDefinition(): HandlerCommand
    {
        return new HandlerCommand(
            name: 'import:run-all',
            description: 'Run every registered migration in dependency order (FR-033).',
            options: [
                new HandlerOption(name: 'dry-run', mode: HandlerOptionMode::None),
                new HandlerOption(name: 'halt-on-error', mode: HandlerOptionMode::None),
                new HandlerOption(name: 'limit', mode: HandlerOptionMode::Required),
            ],
            handler: [ImportRunAllCommand::class, 'execute'],
        );
    }

    private function makeContainer(ImportRunAllCommand $command): ContainerInterface
    {
        return new class($command) implements ContainerInterface {
            public function __construct(private readonly ImportRunAllCommand $command) {}
            public function get(string $id): mixed
            {
                if ($id === ImportRunAllCommand::class) {
                    return $this->command;
                }
                throw new \RuntimeException("Not bound: {$id}");
            }
            public function has(string $id): bool
            {
                return $id === ImportRunAllCommand::class;
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
