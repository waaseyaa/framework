<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Import;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Command\HandlerArgument;
use Waaseyaa\CLI\Command\HandlerArgumentMode;
use Waaseyaa\CLI\Command\Import\ImportStatusCommand;
use Waaseyaa\CLI\Command\HandlerCommand;
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
use Waaseyaa\Migration\Schema\MigrationIdMapSchema;
use Waaseyaa\Migration\Schema\MigrationRunStateSchema;
use Waaseyaa\Migration\SourceId;

#[CoversClass(ImportStatusCommand::class)]
final class ImportStatusCommandTest extends TestCase
{
    #[Test]
    public function lists_three_states_for_three_migrations(): void
    {
        $idMap = $this->freshIdMap($database);
        $runState = new MigrationRunState($database);

        // mig_complete: source count 2, id-map rows 2 → complete.
        $migComplete = $this->definition('mig_complete', ['a', 'b']);
        $idMap->upsert('mig_complete', new SourceId('in_memory', ['id' => 'a']), 'node', 'u1', 'h', 'r', new \DateTimeImmutable('2026-05-13T10:00:00Z'));
        $idMap->upsert('mig_complete', new SourceId('in_memory', ['id' => 'b']), 'node', 'u2', 'h', 'r', new \DateTimeImmutable('2026-05-13T10:01:00Z'));

        // mig_partial: source count 5, id-map rows 2 → partial.
        $migPartial = $this->definition('mig_partial', ['a', 'b', 'c', 'd', 'e']);
        $idMap->upsert('mig_partial', new SourceId('in_memory', ['id' => 'a']), 'node', 'u3', 'h', 'r', new \DateTimeImmutable('2026-05-13T09:00:00Z'));
        $idMap->upsert('mig_partial', new SourceId('in_memory', ['id' => 'b']), 'node', 'u4', 'h', 'r', new \DateTimeImmutable('2026-05-13T09:05:00Z'));
        // Two skipped + one failed record for mig_partial, surfaced by WP07.
        $runState->recordSkipped('mig_partial', 'h-skip-1', 'r', 1);
        $runState->recordSkipped('mig_partial', 'h-skip-2', 'r', 2);
        $runState->recordError('mig_partial', 'h-fail-1', 'r', 3, 'TEST_FAILURE', 'oops');

        // mig_pending: no id-map rows.
        $migPending = $this->definition('mig_pending', ['a']);

        $tester = $this->makeTester([$migComplete, $migPartial, $migPending], $idMap, $runState);
        $tester->execute([]);

        self::assertSame(0, $tester->getExitCode());
        $stdout = $tester->getStdout();
        self::assertStringContainsString('mig_complete', $stdout);
        self::assertStringContainsString('complete', $stdout);
        self::assertStringContainsString('mig_partial', $stdout);
        self::assertStringContainsString('partial', $stdout);
        self::assertStringContainsString('mig_pending', $stdout);
        self::assertStringContainsString('pending', $stdout);
        // Header.
        self::assertStringContainsString('ID', $stdout);
        self::assertStringContainsString('STATE', $stdout);
        self::assertStringContainsString('LAST RUN', $stdout);
        // mig_complete's last run timestamp surfaces in the row.
        self::assertStringContainsString('2026-05-13T10:01:00Z', $stdout);
        // mig_partial now reports real failed/skipped counts sourced from
        // `migration_run_state` (WP07 — FR-038).
        self::assertStringContainsString('partial', $stdout);
    }

    #[Test]
    public function filter_argument_narrows_output(): void
    {
        $idMap = $this->freshIdMap($database);
        $runState = new MigrationRunState($database);
        $migA = $this->definition('mig_a', ['x']);
        $migB = $this->definition('mig_b', ['y']);

        $tester = $this->makeTester([$migA, $migB], $idMap, $runState);
        $tester->execute(['mig_b']);

        self::assertSame(0, $tester->getExitCode());
        $stdout = $tester->getStdout();
        self::assertStringContainsString('mig_b', $stdout);
        // Each id appears once in the row + once in nowhere else (header has 'ID' only).
        self::assertSame(1, \substr_count($stdout, 'mig_b'));
        self::assertSame(0, \substr_count($stdout, 'mig_a'));
    }

    #[Test]
    public function unknown_filter_returns_usage_error(): void
    {
        $idMap = $this->freshIdMap($database);
        $runState = new MigrationRunState($database);
        $tester = $this->makeTester([$this->definition('mig_a', [])], $idMap, $runState);
        $tester->execute(['nope']);

        self::assertSame(2, $tester->getExitCode());
        self::assertStringContainsString('unknown migration "nope"', $tester->getStderr());
    }

    #[Test]
    public function failed_and_skipped_columns_reflect_run_state(): void
    {
        $idMap = $this->freshIdMap($database);
        $runState = new MigrationRunState($database);

        $definition = $this->definition('demo', ['x', 'y', 'z']);
        $runState->recordSuccess('demo', 'h-succ', 'r', 1);
        $runState->recordSkipped('demo', 'h-skip', 'r', 2);
        $runState->recordError('demo', 'h-fail', 'r', 3, 'TEST_FAILURE', 'boom');

        $tester = $this->makeTester([$definition], $idMap, $runState);
        $tester->execute([]);

        self::assertSame(0, $tester->getExitCode());
        $stdout = $tester->getStdout();

        // Header columns.
        self::assertStringContainsString('FAILED', $stdout);
        self::assertStringContainsString('SKIPPED', $stdout);
        // Row shows non-zero failed and skipped counts (FR-038).
        self::assertMatchesRegularExpression(
            '/demo\s+\S+\s+\S+\s+\S+\s+1\s+1\s+/',
            $stdout,
            'demo row should show FAILED=1 and SKIPPED=1 columns sourced from migration_run_state',
        );
    }

    /**
     * @param list<string> $ids
     */
    private function definition(string $migrationId, array $ids): MigrationDefinition
    {
        $records = \array_map(
            static fn(string $id): SourceRecord => new SourceRecord('in_memory', ['id' => $id, 'value' => 'v']),
            $ids,
        );
        return new MigrationDefinition(
            id: $migrationId,
            source: new InMemorySource(id: 'in_memory', records: $records),
            process: ['value' => 'value'],
            destination: new InMemoryDestination(),
        );
    }

    private function freshIdMap(?DBALDatabase &$database = null): MigrationIdMap
    {
        $database = DBALDatabase::createSqlite();
        $database->getConnection()->executeStatement(MigrationIdMapSchema::createTableSql());
        foreach (MigrationIdMapSchema::createIndexSqls() as $sql) {
            $database->getConnection()->executeStatement($sql);
        }
        // WP07 — `MigrationRunState` queries this table; apply the schema
        // alongside the id-map schema so the status command can query both.
        $database->getConnection()->executeStatement(MigrationRunStateSchema::createTableSql());
        foreach (MigrationRunStateSchema::createIndexSqls() as $sql) {
            $database->getConnection()->executeStatement($sql);
        }
        return new MigrationIdMap($database);
    }

    /**
     * @param list<MigrationDefinition> $definitions
     */
    private function makeTester(
        array $definitions,
        MigrationIdMap $idMap,
        MigrationRunState $runState,
    ): CliTester {
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

        $command = new ImportStatusCommand($registry, $idMap, $runState);
        $definitionCli = new HandlerCommand(
            name: 'import:status',
            description: 'Report per-migration import state (FR-034).',
            arguments: [
                new HandlerArgument(name: 'migration_id', mode: HandlerArgumentMode::Optional),
            ],
            handler: [ImportStatusCommand::class, 'execute'],
        );
        $container = new class($command) implements ContainerInterface {
            public function __construct(private readonly ImportStatusCommand $command) {}
            public function get(string $id): mixed
            {
                if ($id === ImportStatusCommand::class) {
                    return $this->command;
                }
                throw new \RuntimeException("Not bound: {$id}");
            }
            public function has(string $id): bool
            {
                return $id === ImportStatusCommand::class;
            }
        };

        return CliTester::for($definitionCli, $container);
    }
}
