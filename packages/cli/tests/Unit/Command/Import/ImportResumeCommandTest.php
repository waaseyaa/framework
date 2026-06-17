<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Import;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Command\HandlerArgument;
use Waaseyaa\CLI\Command\HandlerArgumentMode;
use Waaseyaa\CLI\Command\Import\ImportResumeCommand;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;
use Waaseyaa\Migration\Discovery\HasMigrationsInterface;
use Waaseyaa\Migration\Discovery\MigrationRegistry;
use Waaseyaa\Migration\MigrationDefinition;
use Waaseyaa\Migration\MigrationIdMap;
use Waaseyaa\Migration\MigrationRunState;
use Waaseyaa\Migration\Plugin\SourceRecord;
use Waaseyaa\Migration\PluginFixtures\InMemoryDestination;
use Waaseyaa\Migration\PluginFixtures\InMemorySource;
use Waaseyaa\Migration\Runner\MigrationLock;
use Waaseyaa\Migration\Runner\MigrationRunner;
use Waaseyaa\Migration\Runner\ProcessChainExecutor;
use Waaseyaa\Migration\Runner\RunOptions;

#[CoversClass(ImportResumeCommand::class)]
final class ImportResumeCommandTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Happy + sad path
    // -------------------------------------------------------------------------

    #[Test]
    public function resume_after_partial_run_completes_remainder(): void
    {
        [$tester, $runner] = $this->makeRig(10);

        // First leg: half the records via runtime.
        $runner->run('demo', new RunOptions(limit: 5));

        // Resume via the CLI.
        $tester->execute(['demo']);
        self::assertSame(
            0,
            $tester->getExitCode(),
            "exit must be 0 on success, got stdout={$tester->getStdout()} stderr={$tester->getStderr()}",
        );
        self::assertStringContainsString('demo:', $tester->getStdout());
    }

    #[Test]
    public function resume_without_prior_run_exits_one(): void
    {
        [$tester] = $this->makeRig(10);

        $tester->execute(['demo']);
        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('no prior run recorded', $tester->getStderr());
    }

    #[Test]
    public function unknown_migration_id_exits_two(): void
    {
        [$tester] = $this->makeRig(10);

        $tester->execute(['no-such-migration']);
        self::assertSame(2, $tester->getExitCode());
        self::assertStringContainsString('unknown migration', $tester->getStderr());
    }

    #[Test]
    public function malformed_limit_exits_two(): void
    {
        [$tester] = $this->makeRig(10);

        $tester->execute(['demo', '--limit=abc']);
        self::assertSame(2, $tester->getExitCode());
        self::assertStringContainsString('--limit', $tester->getStderr());
    }

    // -------------------------------------------------------------------------
    // Test rig
    // -------------------------------------------------------------------------

    /**
     * Build a CliTester pre-wired with a fresh in-memory database, an
     * `demo` migration with `$recordCount` records, and the `MigrationRunner`
     * + `ImportResumeCommand` collaborators.
     *
     * Tests get back BOTH the tester and the runner so they can drive an
     * initial `run()` (to seed the prior-run state) before invoking the
     * tester for the actual resume.
     *
     * @return array{0: CliTester, 1: MigrationRunner}
     */
    private function makeRig(int $recordCount): array
    {
        $database = DBALDatabase::createSqlite();

        $idMapMigration = require \dirname(__DIR__, 5)
            . '/migration/migrations/2026_05_13_000001_create_migration_id_map.php';
        \assert($idMapMigration instanceof Migration);
        $runStateMigration = require \dirname(__DIR__, 5)
            . '/migration/migrations/2026_05_13_000002_create_migration_run_state.php';
        \assert($runStateMigration instanceof Migration);

        $schema = new SchemaBuilder($database->getConnection());
        $idMapMigration->up($schema);
        $runStateMigration->up($schema);

        $idMap = new MigrationIdMap($database);
        $runState = new MigrationRunState($database);

        $records = [];
        for ($i = 1; $i <= $recordCount; $i++) {
            $records[] = new SourceRecord('in_memory', [
                'id' => (string) $i,
                'value' => 'v' . $i,
            ]);
        }
        $definition = new MigrationDefinition(
            id: 'demo',
            source: new InMemorySource(id: 'in_memory', records: $records),
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

        $runner = new MigrationRunner(
            registry: $registry,
            chain: new ProcessChainExecutor(),
            idMap: $idMap,
            runState: $runState,
        );

        $command = new ImportResumeCommand($runner, $registry, self::makeLockFactory());

        $tester = CliTester::for(
            $this->commandDefinition(),
            $this->makeContainer($command),
        );

        return [$tester, $runner];
    }

    private function commandDefinition(): HandlerCommand
    {
        return new HandlerCommand(
            name: 'import:resume',
            description: 'Resume the most recent run of one migration (FR-037).',
            arguments: [
                new HandlerArgument(name: 'migration_id', mode: HandlerArgumentMode::Required),
            ],
            options: [
                new HandlerOption(name: 'dry-run', mode: HandlerOptionMode::None),
                new HandlerOption(name: 'halt-on-error', mode: HandlerOptionMode::None),
                new HandlerOption(name: 'limit', mode: HandlerOptionMode::Required),
            ],
            handler: [ImportResumeCommand::class, 'execute'],
        );
    }

    private function makeContainer(ImportResumeCommand $command): ContainerInterface
    {
        return new class($command) implements ContainerInterface {
            public function __construct(private readonly ImportResumeCommand $command) {}
            public function get(string $id): mixed
            {
                if ($id === ImportResumeCommand::class) {
                    return $this->command;
                }
                throw new \RuntimeException("Not bound: {$id}");
            }
            public function has(string $id): bool
            {
                return $id === ImportResumeCommand::class;
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
