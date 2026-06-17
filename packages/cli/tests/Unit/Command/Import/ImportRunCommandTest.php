<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Import;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Command\HandlerArgument;
use Waaseyaa\CLI\Command\HandlerArgumentMode;
use Waaseyaa\CLI\Command\Import\ImportRunCommand;
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

#[CoversClass(ImportRunCommand::class)]
final class ImportRunCommandTest extends TestCase
{
    #[Test]
    public function success_path_prints_summary_and_returns_zero(): void
    {
        $records = $this->makeRecords(['a', 'b', 'c']);
        $source = new InMemorySource(id: 'in_memory', records: $records);
        $destination = new InMemoryDestination();

        $tester = $this->makeTester(new MigrationDefinition(
            id: 'demo',
            source: $source,
            process: ['value' => 'value'],
            destination: $destination,
        ));

        $tester->execute(['demo']);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('demo: complete (3/3, 0 failed, 0 skipped)', $tester->getStdout());
    }

    #[Test]
    public function dry_run_skips_writes(): void
    {
        $records = $this->makeRecords(['a', 'b']);
        $destination = new InMemoryDestination();
        $tester = $this->makeTester(new MigrationDefinition(
            id: 'demo',
            source: new InMemorySource(id: 'in_memory', records: $records),
            process: ['value' => 'value'],
            destination: $destination,
        ));

        $tester->execute(['demo', '--dry-run']);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('demo: complete (2/2, 0 failed, 2 skipped)', $tester->getStdout());
        self::assertCount(0, $destination->writes);
    }

    #[Test]
    public function limit_truncates_processed_records(): void
    {
        $records = $this->makeRecords(['a', 'b', 'c', 'd', 'e']);
        $tester = $this->makeTester(new MigrationDefinition(
            id: 'demo',
            source: new InMemorySource(id: 'in_memory', records: $records),
            process: ['value' => 'value'],
            destination: new InMemoryDestination(),
        ));

        $tester->execute(['demo', '--limit=2']);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('(2/5, 0 failed, 0 skipped)', $tester->getStdout());
    }

    #[Test]
    public function unknown_migration_exits_with_usage_error(): void
    {
        $tester = $this->makeTester(new MigrationDefinition(
            id: 'demo',
            source: new InMemorySource(id: 'in_memory', records: []),
            process: ['value' => 'value'],
            destination: new InMemoryDestination(),
        ));

        $tester->execute(['nonexistent']);

        self::assertSame(2, $tester->getExitCode());
        self::assertStringContainsString('unknown migration "nonexistent"', $tester->getStderr());
    }

    #[Test]
    public function per_record_errors_default_continue_and_exit_one(): void
    {
        $records = $this->makeRecords(['a', 'b']);
        $definition = new MigrationDefinition(
            id: 'demo',
            source: new InMemorySource(id: 'in_memory', records: $records),
            process: ['body' => [new AlwaysFailingProcessor()]],
            destination: new InMemoryDestination(),
        );

        $tester = $this->makeTester($definition);
        $tester->execute(['demo']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('failed (2/2, 2 failed', $tester->getStdout());
        // Error table renders the typed code (FR-046).
        self::assertStringContainsString('TEST_FAILURE', $tester->getStdout());
    }

    #[Test]
    public function halt_on_error_aborts_with_exit_five(): void
    {
        $records = $this->makeRecords(['a', 'b']);
        $definition = new MigrationDefinition(
            id: 'demo',
            source: new InMemorySource(id: 'in_memory', records: $records),
            process: ['body' => [new AlwaysFailingProcessor()]],
            destination: new InMemoryDestination(),
        );

        $tester = $this->makeTester($definition);
        $tester->execute(['demo', '--halt-on-error']);

        self::assertSame(5, $tester->getExitCode());
        self::assertStringContainsString('Aborted', $tester->getStderr());
    }

    #[Test]
    public function malformed_limit_returns_usage_error(): void
    {
        $tester = $this->makeTester(new MigrationDefinition(
            id: 'demo',
            source: new InMemorySource(id: 'in_memory', records: []),
            process: ['value' => 'value'],
            destination: new InMemoryDestination(),
        ));

        $tester->execute(['demo', '--limit=not-a-number']);

        self::assertSame(2, $tester->getExitCode());
        self::assertStringContainsString('--limit must be a positive integer', $tester->getStderr());
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

    private function makeTester(MigrationDefinition $definition): CliTester
    {
        $database = DBALDatabase::createSqlite();
        $database->getConnection()->executeStatement(MigrationIdMapSchema::createTableSql());
        foreach (MigrationIdMapSchema::createIndexSqls() as $sql) {
            $database->getConnection()->executeStatement($sql);
        }
        $idMap = new MigrationIdMap($database);

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

        $runner = new MigrationRunner(
            registry: $registry,
            chain: new ProcessChainExecutor(),
            idMap: $idMap,
        );

        $command = new ImportRunCommand($runner, $registry, self::makeLockFactory());

        $definitionCli = $this->commandDefinition($command);
        $container = $this->makeContainer($command);
        return CliTester::for($definitionCli, $container);
    }

    private function commandDefinition(ImportRunCommand $handler): HandlerCommand
    {
        return new HandlerCommand(
            name: 'import:run',
            description: 'Run a single migration end-to-end (FR-032).',
            arguments: [
                new HandlerArgument(name: 'migration_id', mode: HandlerArgumentMode::Required),
            ],
            options: [
                new HandlerOption(name: 'dry-run', mode: HandlerOptionMode::None),
                new HandlerOption(name: 'halt-on-error', mode: HandlerOptionMode::None),
                new HandlerOption(name: 'limit', mode: HandlerOptionMode::Required),
                new HandlerOption(name: 'run-id', mode: HandlerOptionMode::Required),
            ],
            handler: [ImportRunCommand::class, 'execute'],
        );
    }

    private function makeContainer(ImportRunCommand $command): ContainerInterface
    {
        return new class($command) implements ContainerInterface {
            public function __construct(private readonly ImportRunCommand $command) {}
            public function get(string $id): mixed
            {
                if ($id === ImportRunCommand::class) {
                    return $this->command;
                }
                throw new \RuntimeException("Not bound: {$id}");
            }
            public function has(string $id): bool
            {
                return $id === ImportRunCommand::class;
            }
        };
    }

    /**
     * Build a per-test lock factory pointing into a freshly-created temp
     * directory; the directory is cleaned up by PHP's tempnam contract.
     *
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
