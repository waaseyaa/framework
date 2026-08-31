<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Handler\MigrateHandler;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Command\Migrate\DryRunFormatter;
use Waaseyaa\CLI\Command\Migrate\DryRunNode;
use Waaseyaa\CLI\Command\Migrate\DryRunPlanner;
use Waaseyaa\CLI\Command\Migrate\DryRunResult;
use Waaseyaa\CLI\Command\Migrate\VerifyAuthorityResult;
use Waaseyaa\CLI\Command\Migrate\VerifyFormatter;
use Waaseyaa\CLI\Command\Migrate\VerifyOutcome;
use Waaseyaa\CLI\Command\Migrate\VerifyResultRow;
use Waaseyaa\CLI\Command\Migrate\VerifyRunner;
use Waaseyaa\CLI\Command\Migrate\VerifySummary;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Foundation\Migration\Executor\V2PlanExecutor;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\MigrationCatalogFingerprint;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\Migrator;
use Waaseyaa\Foundation\Migration\SchemaBuilder;
use Waaseyaa\Foundation\Schema\Compiler\Sqlite\SqliteCompiler;
use Waaseyaa\Foundation\Schema\Diff\AddColumn;
use Waaseyaa\Foundation\Schema\Diff\ColumnSpec;
use Waaseyaa\Foundation\Schema\Diff\CompositeDiff;
use Waaseyaa\Foundation\Schema\Migration\MigrationInterfaceV2;
use Waaseyaa\Foundation\Schema\Migration\MigrationPlan;

#[CoversClass(MigrateHandler::class)]
#[CoversClass(DryRunFormatter::class)]
#[CoversClass(DryRunNode::class)]
#[CoversClass(DryRunPlanner::class)]
#[CoversClass(DryRunResult::class)]
#[CoversClass(VerifyAuthorityResult::class)]
#[CoversClass(VerifyFormatter::class)]
#[CoversClass(VerifyOutcome::class)]
#[CoversClass(VerifyResultRow::class)]
#[CoversClass(VerifyRunner::class)]
#[CoversClass(VerifySummary::class)]
final class MigrateHandlerDryRunVerifyTest extends TestCase
{
    #[Test]
    public function dryRunPrintsPlanWithoutApplyingSqlOrLedgerWrites(): void
    {
        [$connection, $repo, $tester] = self::buildHarness([self::v2Adding('widgets', 'archived_at')]);
        $connection->executeStatement('CREATE TABLE widgets (id INTEGER PRIMARY KEY)');

        $tester->execute(['--dry-run']);

        self::assertSame(0, $tester->getExitCode());
        $output = $tester->getStdout();
        self::assertStringContainsString('Dry-run plan', $output);
        self::assertStringContainsString('waaseyaa/test:v2:foo', $output);
        self::assertStringContainsString('ALTER TABLE "widgets" ADD COLUMN "archived_at"', $output);

        // No ledger row was written.
        self::assertCount(0, $repo->allWithChecksums());

        // No archived_at column was added.
        $columns = array_column(
            $connection->executeQuery('PRAGMA table_info(widgets)')->fetchAllAssociative(),
            'name',
        );
        self::assertNotContains('archived_at', $columns);
    }

    #[Test]
    public function dryRunOmitsSqlForAnOperationTheLiveSchemaAlreadySatisfies(): void
    {
        // #2701: the same immutable catalogue serves a fresh site and an
        // upgraded one. Advertising SQL that apply would skip makes the plan
        // untrue for one of them.
        [$connection, $repo, $tester] = self::buildHarness([self::v2Adding('widgets', 'archived_at')]);
        $connection->executeStatement('CREATE TABLE widgets (id INTEGER PRIMARY KEY, archived_at INTEGER)');

        $tester->execute(['--dry-run']);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringNotContainsString(
            'ALTER TABLE "widgets" ADD COLUMN "archived_at"',
            $tester->getStdout(),
            'the column already exists, so apply would issue no SQL for it',
        );
        self::assertCount(0, $repo->allWithChecksums(), 'dry-run still writes no ledger row');
    }

    #[Test]
    public function dryRunStillReportsSqlForAnOutstandingOperation(): void
    {
        [$connection, , $tester] = self::buildHarness([self::v2Adding('widgets', 'archived_at')]);
        $connection->executeStatement('CREATE TABLE widgets (id INTEGER PRIMARY KEY)');

        $tester->execute(['--dry-run']);

        self::assertStringContainsString(
            'ALTER TABLE "widgets" ADD COLUMN "archived_at"',
            $tester->getStdout(),
        );
    }

    /**
     * R5: an operation whose state an earlier operation in the same plan changes
     * must be preserved in the plan and marked state-dependent. Filtering it
     * against the initial snapshot silently omitted work that apply performs.
     */
    #[Test]
    public function dryRunPreservesAnOperationAPredecessorMakesNecessary(): void
    {
        [$connection, , $tester] = self::buildHarness([self::v2Swapping('widgets', 'archived_at')]);
        $connection->executeStatement('CREATE TABLE widgets (id INTEGER PRIMARY KEY, archived_at TEXT)');

        $tester->execute(['--dry-run', '--json']);

        self::assertSame(0, $tester->getExitCode());
        $payload = json_decode($tester->getStdout(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        $node = $payload['nodes'][0];
        self::assertTrue($node['state_dependent'], 'the plan cannot resolve this node exactly');
        self::assertCount(2, $node['steps'], 'both operations are preserved, not filtered');
    }

    #[Test]
    public function dryRunJsonOutputMatchesDocumentedSchema(): void
    {
        [$connection, , $tester] = self::buildHarness([self::v2Adding('widgets', 'archived_at')]);
        $connection->executeStatement('CREATE TABLE widgets (id INTEGER PRIMARY KEY)');

        $tester->execute(['--dry-run', '--json']);

        $payload = json_decode($tester->getStdout(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('dry_run', $payload['kind']);
        self::assertArrayHasKey('nodes', $payload);
        self::assertArrayHasKey('summary', $payload);
        self::assertSame(['v2_count', 'legacy_count', 'would_apply'], array_keys($payload['summary']));
        self::assertSame(1, $payload['summary']['v2_count']);
        self::assertSame(1, $payload['summary']['would_apply']);

        $node = $payload['nodes'][0];
        self::assertSame('waaseyaa/test:v2:foo', $node['id']);
        self::assertSame('v2', $node['kind']);
        self::assertFalse($node['already_applied']);
        self::assertNotEmpty($node['steps']);
        self::assertSame('alter_table_add_column', $node['steps'][0]['kind']);
    }

    #[Test]
    public function verifyAllMatchExitsZero(): void
    {
        [$connection, $repo, $tester] = self::buildHarness([self::v2Adding('widgets', 'archived_at')]);
        $connection->executeStatement('CREATE TABLE widgets (id INTEGER PRIMARY KEY)');

        // Apply first so the ledger has a stored checksum.
        $migrator = new Migrator(
            $connection,
            $repo,
            new V2PlanExecutor($connection, SqliteCompiler::forVersion('3.40.0')),
        );
        $migrator->run([], [self::v2Adding('widgets', 'archived_at')]);

        $tester->execute(['--verify']);

        self::assertSame(0, $tester->getExitCode());
        $output = $tester->getStdout();
        self::assertStringContainsString('STATUS: OK', $output);
        self::assertStringContainsString('match=1', $output);
        self::assertStringContainsString('mismatch=0', $output);
    }

    #[Test]
    public function verifyMismatchExitsNonZeroAndNamesTheMigration(): void
    {
        [$connection, $repo] = self::makeConnectionAndRepo();
        $connection->executeStatement('CREATE TABLE widgets (id INTEGER PRIMARY KEY)');

        // Apply original.
        $migrator = new Migrator(
            $connection,
            $repo,
            new V2PlanExecutor($connection, SqliteCompiler::forVersion('3.40.0')),
        );
        $migrator->run([], [self::v2Adding('widgets', 'archived_at')]);

        // Build a handler that loads a DIFFERENT v2 plan under the same migration_id — drift the source.
        $tester = self::buildTesterFromHandler(new MigrateHandler(
            migrator: $migrator,
            migrationsProvider: static fn(): array => [],
            v2MigrationsProvider: static fn(): array => [self::v2Adding('widgets', 'deleted_at')],
            repository: $repo,
            compiler: SqliteCompiler::forVersion('3.40.0'),
            isProduction: true,
        ));

        $tester->execute(['--verify']);

        self::assertNotSame(0, $tester->getExitCode());
        $output = $tester->getStdout();
        self::assertStringContainsString('mismatch', $output);
        self::assertStringContainsString('waaseyaa/test:v2:foo', $output);
    }

    #[Test]
    public function verifyRejectsAnUnverifiableLegacyLedgerRow(): void
    {
        [$connection, $repo] = self::makeConnectionAndRepo();
        $repo->record('waaseyaa/test:legacy', 'waaseyaa/test', 1);
        $legacy = new class extends Migration {
            public function up(SchemaBuilder $schema): void {}
        };
        $migrator = new Migrator($connection, $repo);
        $tester = self::buildTesterFromHandler(new MigrateHandler(
            migrator: $migrator,
            migrationsProvider: static fn(): array => [
                'waaseyaa/test' => ['waaseyaa/test:legacy' => $legacy],
            ],
            repository: $repo,
            compiler: SqliteCompiler::forVersion('3.40.0'),
            isProduction: true,
        ));

        $tester->execute(['--verify']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('unknown=1', $tester->getStdout());
        self::assertStringContainsString('STATUS: FAIL', $tester->getStdout());
    }

    #[Test]
    public function verifyRejectsALedgerPackageMismatch(): void
    {
        [$connection, $repo] = self::makeConnectionAndRepo();
        $migration = self::v2Adding('widgets', 'archived_at');
        $plan = $migration->plan();
        $compiled = SqliteCompiler::forVersion('3.40.0')->compile($plan->root);
        $repo->record($migration->migrationId(), 'waaseyaa/wrong-package', 1, $plan->checksum(), $compiled->diffHash());
        $tester = self::verifyTester($connection, $repo, [$migration]);

        $tester->execute(['--verify']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('package_mismatch', $tester->getStdout());
    }

    #[Test]
    public function verifyRejectsACompiledPlanHashMismatch(): void
    {
        [$connection, $repo] = self::makeConnectionAndRepo();
        $migration = self::v2Adding('widgets', 'archived_at');
        $plan = $migration->plan();
        $repo->record($migration->migrationId(), $migration->package(), 1, $plan->checksum(), str_repeat('0', 64));
        $tester = self::verifyTester($connection, $repo, [$migration]);

        $tester->execute(['--verify']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('plan_mismatch', $tester->getStdout());
    }

    #[Test]
    public function verifyRejectsLiveSchemaDriftAfterASuccessfulApply(): void
    {
        [$connection, $repo] = self::makeConnectionAndRepo();
        $connection->executeStatement('CREATE TABLE widgets (id INTEGER PRIMARY KEY)');
        $migration = self::v2Adding('widgets', 'archived_at');
        $migrator = new Migrator(
            $connection,
            $repo,
            new V2PlanExecutor($connection, SqliteCompiler::forVersion('3.40.0')),
        );
        $migrator->run([], [$migration]);
        $connection->executeStatement('ALTER TABLE widgets ADD COLUMN rogue_runtime_column TEXT');
        $tester = self::verifyTester($connection, $repo, [$migration], $migrator);

        $tester->execute(['--verify']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('schema_drift', $tester->getStdout());
    }

    #[Test]
    public function verifyMissingAuthorityFailsWithoutInstallingSchema(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $repo = new MigrationRepository($connection);
        $tester = self::verifyTester($connection, $repo, []);

        $tester->execute(['--verify']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('authority_missing', $tester->getStdout());
        self::assertSame([], $connection->fetchFirstColumn(
            "SELECT name FROM sqlite_schema WHERE name LIKE 'waaseyaa_%' ORDER BY name",
        ));
    }

    #[Test]
    public function verifyJsonClassifiesVerifiedLegacyAndOrphanRows(): void
    {
        [$connection, $repo] = self::makeConnectionAndRepo();
        $legacy = new class extends Migration {
            public function up(SchemaBuilder $schema): void {}
        };
        $driftedLegacy = new class extends Migration {
            public function up(SchemaBuilder $schema): void {}
        };
        $checksum = MigrationCatalogFingerprint::legacySourceChecksum($legacy);
        $repo->record('waaseyaa/test:legacy', 'waaseyaa/test', 1, $checksum, MigrationCatalogFingerprint::legacyPlanHash($checksum));
        $repo->record('waaseyaa/missing:orphan', 'waaseyaa/missing', 2, str_repeat('a', 64), str_repeat('b', 64));
        $repo->record('waaseyaa/test:drifted', 'waaseyaa/test', 3, str_repeat('c', 64), str_repeat('d', 64));
        $migrator = new Migrator($connection, $repo);
        $tester = self::buildTesterFromHandler(new MigrateHandler(
            migrator: $migrator,
            migrationsProvider: static fn(): array => [
                'waaseyaa/test' => [
                    'waaseyaa/test:legacy' => $legacy,
                    'waaseyaa/test:drifted' => $driftedLegacy,
                ],
            ],
            v2MigrationsProvider: static fn(): array => [],
            repository: $repo,
            compiler: SqliteCompiler::forVersion('3.40.0'),
            isProduction: true,
        ));

        $tester->execute(['--verify', '--json']);

        self::assertSame(1, $tester->getExitCode());
        $payload = json_decode($tester->getStdout(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('verify', $payload['kind']);
        self::assertSame(
            [
                'waaseyaa/test:legacy' => 'match',
                'waaseyaa/missing:orphan' => 'orphan',
                'waaseyaa/test:drifted' => 'mismatch',
            ],
            array_column($payload['results'], 'status', 'migration'),
        );
        self::assertSame(1, $payload['summary']['orphan']);
        self::assertSame(5, (new VerifySummary(1, 1, 1, 1, 1))->total());
    }

    #[Test]
    public function dryRunAndVerifyTogetherFailWithIncompatibleFlags(): void
    {
        [, , $tester] = self::buildHarness([]);

        $tester->execute(['--dry-run', '--verify']);

        self::assertSame(2, $tester->getExitCode());
        self::assertStringContainsString('INCOMPATIBLE_FLAGS', $tester->getStderr());
    }

    #[Test]
    public function productionSanitizationStripsAbsolutePathsFromOutput(): void
    {
        [$connection, , $tester] = self::buildHarness(
            [self::v2Adding('widgets', 'archived_at')],
            isProduction: true,
        );
        $connection->executeStatement('CREATE TABLE widgets (id INTEGER PRIMARY KEY)');

        $tester->execute(['--dry-run', '--json']);

        $payload = json_decode($tester->getStdout(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        // No /home/, /var/, /tmp/ leaked anywhere in the JSON output.
        self::assertDoesNotMatchRegularExpression('#/home/|/var/|/tmp/#', $tester->getStdout());
    }

    /**
     * @param list<MigrationInterfaceV2> $v2
     * @return array{0: \Doctrine\DBAL\Connection, 1: MigrationRepository, 2: CliTester}
     */
    private static function v2Swapping(string $table, string $column): MigrationInterfaceV2
    {
        return new class ($table, $column) implements MigrationInterfaceV2 {
            public function __construct(private string $table, private string $column) {}

            public function migrationId(): string
            {
                return 'waaseyaa/test:v2:swap';
            }

            public function package(): string
            {
                return 'waaseyaa/test';
            }

            public function dependencies(): array
            {
                return [];
            }

            public function plan(): MigrationPlan
            {
                return new MigrationPlan(
                    migrationId: $this->migrationId(),
                    package: $this->package(),
                    dependencies: [],
                    // Non-destructive but state-dependent: the rename frees the
                    // name the second operation then re-adds. Judged against the
                    // initial snapshot, the AddColumn looks already satisfied.
                    root: new CompositeDiff([
                        new \Waaseyaa\Foundation\Schema\Diff\RenameColumn($this->table, $this->column, 'archived_at_old'),
                        new AddColumn($this->table, $this->column, new ColumnSpec(type: 'text', nullable: true)),
                    ]),
                );
            }
        };
    }

    private static function buildHarness(array $v2, bool $isProduction = false): array
    {
        [$connection, $repo] = self::makeConnectionAndRepo();

        $migrator = new Migrator(
            $connection,
            $repo,
            new V2PlanExecutor($connection, SqliteCompiler::forVersion('3.40.0')),
        );

        $handler = new MigrateHandler(
            migrator: $migrator,
            migrationsProvider: static fn(): array => [],
            v2MigrationsProvider: static fn(): array => $v2,
            repository: $repo,
            compiler: SqliteCompiler::forVersion('3.40.0'),
            isProduction: $isProduction,
            connection: $connection,
        );

        return [$connection, $repo, self::buildTesterFromHandler($handler)];
    }

    private static function buildTesterFromHandler(MigrateHandler $handler): CliTester
    {
        $definition = new HandlerCommand(
            name: 'migrate',
            description: 'Run pending database migrations (use --dry-run to preview, --verify to audit)',
            options: [
                new HandlerOption(name: 'dry-run', mode: HandlerOptionMode::None, description: 'Preview pending migrations without applying any SQL or writing to the ledger.'),
                new HandlerOption(name: 'verify', mode: HandlerOptionMode::None, description: 'Compare ledger checksums against the live source. Read-only.'),
                new HandlerOption(name: 'json', mode: HandlerOptionMode::None, description: 'Emit machine-readable JSON instead of human-readable text.'),
            ],
            handler: \Closure::fromCallable([$handler, 'execute']),
        );

        $container = new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed { throw new \RuntimeException("Not found: $id"); }
            public function has(string $id): bool { return false; }
        };

        return CliTester::for($definition, $container);
    }

    /**
     * @param list<MigrationInterfaceV2> $v2
     */
    private static function verifyTester(
        \Doctrine\DBAL\Connection $connection,
        MigrationRepository $repo,
        array $v2,
        ?Migrator $migrator = null,
    ): CliTester {
        $migrator ??= new Migrator(
            $connection,
            $repo,
            new V2PlanExecutor($connection, SqliteCompiler::forVersion('3.40.0')),
        );

        return self::buildTesterFromHandler(new MigrateHandler(
            migrator: $migrator,
            migrationsProvider: static fn(): array => [],
            v2MigrationsProvider: static fn(): array => $v2,
            repository: $repo,
            compiler: SqliteCompiler::forVersion('3.40.0'),
            isProduction: true,
        ));
    }

    /**
     * @return array{0: \Doctrine\DBAL\Connection, 1: MigrationRepository}
     */
    private static function makeConnectionAndRepo(): array
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $repo = new MigrationRepository($connection);
        $repo->createTable();

        return [$connection, $repo];
    }

    private static function v2Adding(string $table, string $column): MigrationInterfaceV2
    {
        return new class ($table, $column) implements MigrationInterfaceV2 {
            public function __construct(
                private readonly string $table,
                private readonly string $column,
            ) {}

            public function migrationId(): string
            {
                return 'waaseyaa/test:v2:foo';
            }

            public function package(): string
            {
                return 'waaseyaa/test';
            }

            public function dependencies(): array
            {
                return [];
            }

            public function plan(): MigrationPlan
            {
                return new MigrationPlan(
                    migrationId: $this->migrationId(),
                    package: $this->package(),
                    dependencies: [],
                    root: new CompositeDiff([
                        new AddColumn($this->table, $this->column, new ColumnSpec(type: 'int', nullable: true)),
                    ]),
                );
            }
        };
    }
}
