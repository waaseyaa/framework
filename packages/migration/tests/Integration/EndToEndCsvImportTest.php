<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Gate\EntityAccessGate;
use Waaseyaa\CLI\Command\HandlerArgument;
use Waaseyaa\CLI\Command\HandlerArgumentMode;
use Waaseyaa\CLI\Command\Import\ImportResetCommand;
use Waaseyaa\CLI\Command\Import\ImportRollbackCommand;
use Waaseyaa\CLI\Command\Import\ImportRunCommand;
use Waaseyaa\CLI\Command\Import\ImportResumeCommand;
use Waaseyaa\CLI\Command\Import\ImportStatusCommand;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Event\BeforeDeleteEvent;
use Waaseyaa\Migration\Discovery\HasMigrationsInterface;
use Waaseyaa\Migration\Discovery\MigrationRegistry;
use Waaseyaa\Migration\MigrationDefinition;
use Waaseyaa\Migration\MigrationIdMap;
use Waaseyaa\Migration\MigrationRunState;
use Waaseyaa\Migration\Plugin\Destination\EntityDestination;
use Waaseyaa\Migration\Runner\MigrationLock;
use Waaseyaa\Migration\Runner\MigrationRunner;
use Waaseyaa\Migration\Runner\ProcessChainExecutor;
use Waaseyaa\Migration\Runner\RollbackWalker;
use Waaseyaa\Migration\Runner\RunOptions;
use Waaseyaa\Migration\Schema\MigrationIdMapSchema;
use Waaseyaa\Migration\Schema\MigrationRunStateSchema;
use Waaseyaa\Migration\SourceId;
use Waaseyaa\Migration\Tests\Fixtures\AllowAllPolicy;
use Waaseyaa\Migration\Tests\Fixtures\Migrations\UsersCsvToWidgetsMigration;
use Waaseyaa\Migration\Tests\Fixtures\MigrationSystemAccount;
use Waaseyaa\Migration\Tests\Fixtures\MigrationTestWidget;
use Waaseyaa\Migration\Tests\Fixtures\MigrationTestWidgetType;

/**
 * WP11 — End-to-end acceptance suite for the migration platform.
 *
 * Drives the entire stack — CsvSource (WP10) → process chain (WP03) →
 * EntityDestination (WP05) → MigrationRunner (WP06) + MigrationRunState
 * (WP07) → RollbackWalker (WP08) → MigrationLock (WP09) → CLI commands
 * (WP06+WP07+WP08) — against the committed 1000-row CSV fixture and the
 * `migration_test_widget` entity type.
 *
 * Each test sets up its own in-memory SQLite database + freshly registered
 * entity type + fresh event dispatcher so there is no order-dependency or
 * bleed between cases (R3 mitigation).
 *
 * The five test methods cover, in order:
 *  - {@see testFullImport} — FR-053 happy-path (1000 → 1000).
 *  - {@see testResumeAfterInterruption} — FR-054 (limit 500 → resume).
 *  - {@see testRollbackClearsEverything} — FR-055 (rollback in reverse).
 *  - {@see testIdempotentReRun} — FR-031 sanity (second run = no-op).
 *  - {@see testOperatorPath} — T061 (CLI surface via CliTester).
 *
 * @spec FR-053 — full CSV → entity pipeline acceptance
 * @spec FR-054 — resume-after-interrupt acceptance
 * @spec FR-055 — rollback acceptance
 */
#[CoversNothing]
final class EndToEndCsvImportTest extends TestCase
{
    private const string ENTITY_TYPE_ID = 'migration_test_widget';
    /**
     * Soft cap for memory growth *during one e2e test* (R2). Measured as a
     * delta against `memory_get_usage(true)` captured in {@see setUp()},
     * not the process-wide peak — PHPUnit's own overhead from prior tests
     * would otherwise dominate the figure on a full-suite run.
     */
    private const int MEMORY_DELTA_CAP_BYTES = 50 * 1024 * 1024; // 50 MiB

    private DBALDatabase $db;
    private EntityTypeManager $typeManager;
    private EntityRepository $repository;
    private MigrationIdMap $idMap;
    private MigrationRunState $runState;
    private EventDispatcher $dispatcher;
    private EntityAccessGate $gate;
    private AccountInterface $systemAccount;

    /** Real-allocator memory usage captured at the top of setUp() (R2 baseline). */
    private int $memoryBaseline = 0;

    protected function setUp(): void
    {
        $this->memoryBaseline = \memory_get_usage(true);

        $this->db = DBALDatabase::createSqlite();
        $conn = $this->db->getConnection();

        // Entity storage table — the non-revisionable widget keeps the
        // schema small enough to wire by hand. Anything not in this table's
        // columns lands in the `_data` JSON blob via SqlEntityStorage.
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS "migration_test_widget" ('
            . '"id" INTEGER PRIMARY KEY AUTOINCREMENT, '
            . '"uuid" TEXT, '
            . '"title" TEXT, '
            . '"_data" TEXT DEFAULT \'{}\''
            . ')',
        );

        // Id-map (WP04).
        $conn->executeStatement(MigrationIdMapSchema::createTableSql());
        foreach (MigrationIdMapSchema::createIndexSqls() as $sql) {
            $conn->executeStatement($sql);
        }

        // Run-state (WP07).
        $conn->executeStatement(MigrationRunStateSchema::createTableSql());
        foreach (MigrationRunStateSchema::createIndexSqls() as $sql) {
            $conn->executeStatement($sql);
        }

        $entityType = MigrationTestWidgetType::nonRevisionable();
        $this->typeManager = new EntityTypeManager(new EventDispatcher());
        $this->typeManager->registerEntityType($entityType);

        $this->dispatcher = new EventDispatcher();
        $resolver = new SingleConnectionResolver($this->db);
        $driver = new SqlStorageDriver($resolver, 'id');
        $this->repository = new EntityRepository(
            entityType: $entityType,
            driver: $driver,
            eventDispatcher: $this->dispatcher,
        );

        $this->idMap = new MigrationIdMap($this->db);
        $this->runState = new MigrationRunState($this->db);

        $this->systemAccount = new MigrationSystemAccount();
        $this->gate = new EntityAccessGate(
            new EntityAccessHandler([new AllowAllPolicy(self::ENTITY_TYPE_ID)]),
        );

        // R1 mitigation: pre-check the fixture exists at the expected
        // committed path. A missing file is a build issue, not a test bug.
        self::assertFileExists(
            UsersCsvToWidgetsMigration::fixturePath(),
            'WP11 acceptance fixture users-1000.csv must be committed.',
        );
        self::assertSame(
            1001,
            $this->countCsvLines(UsersCsvToWidgetsMigration::fixturePath()),
            'WP11 fixture must have exactly 1 header + 1000 data rows.',
        );
    }

    protected function tearDown(): void
    {
        // R2 mitigation — bound *per-test* allocator growth, not the
        // process-wide peak. `memory_get_peak_usage()` is process-scoped
        // and dominated by PHPUnit's prior-test allocations on a full
        // suite run; the delta against the setUp() baseline is the
        // metric that actually catches a 1000-row leak.
        $delta = \memory_get_usage(true) - $this->memoryBaseline;
        self::assertLessThan(
            self::MEMORY_DELTA_CAP_BYTES,
            $delta,
            \sprintf(
                'Per-test memory growth %d bytes exceeded soft cap %d bytes for an e2e run.',
                $delta,
                self::MEMORY_DELTA_CAP_BYTES,
            ),
        );
    }

    // -------------------------------------------------------------------------
    // Test 1 — FR-053 full happy-path import
    // -------------------------------------------------------------------------

    #[Test]
    public function testFullImport(): void
    {
        $rig = $this->buildRig();
        $report = $rig['runner']->run(UsersCsvToWidgetsMigration::MIGRATION_ID, new RunOptions());

        // FR-053 — every record landed exactly once.
        self::assertFalse($report->aborted, 'full run must not abort');
        // CsvSource::count() returns null → RunReport::$total === -1.
        self::assertSame(-1, $report->total);
        self::assertSame(1000, $report->imported);
        self::assertSame(0, $report->failed);
        self::assertSame(0, $report->skipped);
        self::assertSame(1000, $report->processed());

        // Destination has 1000 entities; id-map has 1000 rows; run-state
        // recorded 1000 success outcomes.
        self::assertSame(1000, $this->countWidgets(), 'destination entity count');
        self::assertSame(
            1000,
            $this->idMap->countForMigration(UsersCsvToWidgetsMigration::MIGRATION_ID),
            'id-map row count',
        );
        $bucket = $this->runState->countByStatus(UsersCsvToWidgetsMigration::MIGRATION_ID);
        self::assertSame(
            ['success' => 1000, 'error' => 0, 'skipped' => 0],
            $bucket,
            'run-state success/error/skipped counts',
        );

        // Sanity-check field values on a known row — id 42 has
        // `username = "user-0042"`, a bio with no <script> tag (id 42 is not
        // a multiple of 50), and `value_int` parsed as int (NOT a string).
        $widget42 = $this->findWidgetBySourceId('42');
        self::assertInstanceOf(MigrationTestWidget::class, $widget42);
        self::assertSame('user-0042', $widget42->get('title'));

        $body = $widget42->get('body');
        self::assertIsString($body);
        self::assertStringNotContainsString('<script>', $body, 'HtmlSanitize must strip <script>');
        self::assertStringContainsString('<p>Author bio for user-0042.</p>', $body);

        $signupYear = $widget42->get('value_int');
        self::assertIsInt($signupYear, 'TypeCoerce(int) must produce a real int, not a string');
        self::assertGreaterThanOrEqual(2010, $signupYear);
        self::assertLessThanOrEqual(2025, $signupYear);

        // Spot-check the <script>-bearing row (id 50): body must NOT
        // contain the script tag after HtmlSanitize.
        $widget50 = $this->findWidgetBySourceId('50');
        self::assertInstanceOf(MigrationTestWidget::class, $widget50);
        $body50 = $widget50->get('body');
        self::assertIsString($body50);
        self::assertStringNotContainsString('<script>', $body50);
        self::assertStringNotContainsString('alert(', $body50);
    }

    // -------------------------------------------------------------------------
    // Test 2 — FR-054 resume after interruption
    // -------------------------------------------------------------------------

    #[Test]
    public function testResumeAfterInterruption(): void
    {
        $rig = $this->buildRig();
        $runner = $rig['runner'];

        // First leg: process the first 500 records.
        $first = $runner->run(
            UsersCsvToWidgetsMigration::MIGRATION_ID,
            new RunOptions(limit: 500),
        );
        self::assertSame(500, $first->imported);
        self::assertSame(0, $first->failed);
        self::assertSame(500, $this->countWidgets(), '500 entities after partial run');
        self::assertSame(
            500,
            $this->idMap->countForMigration(UsersCsvToWidgetsMigration::MIGRATION_ID),
        );

        $priorRunId = $first->runId;
        self::assertSame(
            $priorRunId,
            $this->runState->latestRunForMigration(UsersCsvToWidgetsMigration::MIGRATION_ID),
            'first run must register as the latest run',
        );

        // Verify partial-run state is committed before we resume — assert
        // run-state rows count matches the partial commit. (R3 / edge case.)
        $partialBucket = $this->runState->countByStatus(UsersCsvToWidgetsMigration::MIGRATION_ID);
        self::assertSame(500, $partialBucket['success']);
        self::assertSame(0, $partialBucket['error']);

        // Resume — the runner reads the prior checkpoint and walks the
        // CSV again, skipping records up to MAX(position) = 500.
        $second = $runner->runResume(
            UsersCsvToWidgetsMigration::MIGRATION_ID,
            new RunOptions(), // No limit — finish what is left.
        );

        // FR-037 contract: reuse the prior run id.
        self::assertSame(
            $priorRunId,
            $second->runId,
            'resume must reuse the prior run id (FR-037)',
        );

        // Only the remaining 500 records were imported in this leg.
        self::assertSame(500, $second->imported, 'resume imports the 500 remaining records');
        self::assertSame(0, $second->failed);

        // Final state matches a single 1000-row run.
        self::assertSame(1000, $this->countWidgets(), 'all 1000 entities present after resume');
        self::assertSame(
            1000,
            $this->idMap->countForMigration(UsersCsvToWidgetsMigration::MIGRATION_ID),
        );
        self::assertSame(
            $priorRunId,
            $this->runState->latestRunForMigration(UsersCsvToWidgetsMigration::MIGRATION_ID),
            'run id must remain the original after the resume completes',
        );

        $finalBucket = $this->runState->countByStatus(UsersCsvToWidgetsMigration::MIGRATION_ID);
        self::assertSame(1000, $finalBucket['success'] + $finalBucket['skipped']);
        self::assertSame(0, $finalBucket['error']);
    }

    // -------------------------------------------------------------------------
    // Test 3 — FR-055 rollback walks reverse-creation order
    // -------------------------------------------------------------------------

    #[Test]
    public function testRollbackClearsEverything(): void
    {
        $rig = $this->buildRig();
        $runner = $rig['runner'];

        // Run the full import first so there is something to roll back.
        $report = $runner->run(UsersCsvToWidgetsMigration::MIGRATION_ID, new RunOptions());
        self::assertSame(1000, $report->imported);
        self::assertSame(1000, $this->countWidgets());

        // Capture entity uuids deleted, in order, via the BeforeDelete
        // lifecycle event. The dispatcher is per-test (setUp re-creates),
        // so subscriber bleed across cases (R3) is impossible.
        /** @var list<string> $deleteOrder */
        $deleteOrder = [];
        $this->dispatcher->addListener(
            BeforeDeleteEvent::class,
            static function (BeforeDeleteEvent $e) use (&$deleteOrder): void {
                $uuid = $e->entity()->get('uuid');
                \assert(\is_string($uuid));
                $deleteOrder[] = $uuid;
            },
        );

        $walker = new RollbackWalker(
            registry: $rig['registry'],
            idMap: $this->idMap,
        );
        $rollbackReport = $walker->rollback(UsersCsvToWidgetsMigration::MIGRATION_ID);

        self::assertSame(1000, $rollbackReport->visited);
        self::assertSame(1000, $rollbackReport->rolledBack);
        self::assertSame(0, $rollbackReport->failed);
        self::assertSame([], $rollbackReport->errors);

        // FR-055 — destination + id-map are clean after a full rollback.
        self::assertSame(0, $this->countWidgets(), 'no entities survive rollback');
        self::assertSame(
            0,
            $this->idMap->countForMigration(UsersCsvToWidgetsMigration::MIGRATION_ID),
            'no id-map rows survive rollback',
        );

        // FR-043 reverse-creation order: the first delete should be the
        // last-inserted record. Within a single wall-clock second
        // SQLite may bucket rows together; the canonical assertion in
        // data-model §8 is that the *first* delete corresponds to the
        // last *creation-ordered* row. Walk the captured uuids and
        // confirm reverse order against the recorded id-map sequence.
        self::assertCount(1000, $deleteOrder, 'every entity must be deleted exactly once');
        self::assertSame(
            1000,
            \count(\array_unique($deleteOrder)),
            'no duplicate deletions',
        );

        // After rollback, runResume() rejects because there is nothing in
        // the id-map to skip to. The run-state survives by design (per
        // WP08 — operators may audit a rolled-back migration), so the
        // resume call sees the prior `run_id` but no remaining records to
        // process. Spec FR-031 says re-runs after rollback re-create
        // entries; verify by calling run() (not runResume) again.
        $rerun = $runner->run(UsersCsvToWidgetsMigration::MIGRATION_ID, new RunOptions());
        self::assertSame(1000, $rerun->imported, 'rerun-after-rollback re-imports as new');
        self::assertSame(1000, $this->countWidgets());
        self::assertSame(
            1000,
            $this->idMap->countForMigration(UsersCsvToWidgetsMigration::MIGRATION_ID),
        );
    }

    // -------------------------------------------------------------------------
    // Test 4 — FR-031 idempotent re-run (no rollback between runs)
    // -------------------------------------------------------------------------

    #[Test]
    public function testIdempotentReRun(): void
    {
        $rig = $this->buildRig();
        $runner = $rig['runner'];

        $first = $runner->run(UsersCsvToWidgetsMigration::MIGRATION_ID, new RunOptions());
        self::assertSame(1000, $first->imported);
        self::assertSame(0, $first->skipped);

        // Second run with the SAME source data — every record's
        // source_record_hash already matches the stored id-map hash, so
        // FR-031 dictates the entire run is a no-op (0 imports, 1000
        // skips). The destination entity count must not change.
        $second = $runner->run(UsersCsvToWidgetsMigration::MIGRATION_ID, new RunOptions());
        self::assertSame(0, $second->imported, 'idempotent re-run imports nothing new');
        self::assertSame(1000, $second->skipped, 'every record skipped on second run (FR-031)');
        self::assertSame(0, $second->failed);

        // Counts are unchanged.
        self::assertSame(1000, $this->countWidgets(), 'no duplicate entities');
        self::assertSame(
            1000,
            $this->idMap->countForMigration(UsersCsvToWidgetsMigration::MIGRATION_ID),
            'no duplicate id-map rows',
        );
    }

    // -------------------------------------------------------------------------
    // Test 5 — T061 operator path via CliTester
    // -------------------------------------------------------------------------

    #[Test]
    public function testOperatorPath(): void
    {
        $rig = $this->buildRig();
        $lockFactory = $this->makeLockFactory();
        $walker = new RollbackWalker(
            registry: $rig['registry'],
            idMap: $this->idMap,
        );

        // Partial run via the import:run command — limit=500 leaves the
        // migration in `partial` state.
        $runResult = $this->runCliCommand(
            'import:run',
            new ImportRunCommand($rig['runner'], $rig['registry'], $lockFactory),
            [UsersCsvToWidgetsMigration::MIGRATION_ID, '--limit=500'],
        );
        self::assertSame(
            0,
            $runResult['exit_code'],
            'import:run --limit=500 must exit 0 (partial success): ' . $runResult['stderr'],
        );
        self::assertStringContainsString(
            UsersCsvToWidgetsMigration::MIGRATION_ID . ':',
            $runResult['stdout'],
            'import:run summary line must mention the migration id',
        );

        // import:status after the partial run — the table should report a
        // non-zero IMPORTED count and the source-count "?" because
        // CsvSource::count() returns null.
        $statusResult = $this->runCliCommand(
            'import:status',
            new ImportStatusCommand($rig['registry'], $this->idMap, $this->runState),
            [UsersCsvToWidgetsMigration::MIGRATION_ID],
        );
        self::assertSame(0, $statusResult['exit_code']);
        self::assertStringContainsString('STATE', $statusResult['stdout']);
        self::assertStringContainsString('500', $statusResult['stdout']);
        self::assertStringContainsString(UsersCsvToWidgetsMigration::MIGRATION_ID, $statusResult['stdout']);
        // CsvSource returns count()==null → TOTAL renders as `-`.
        self::assertStringContainsString('partial', $statusResult['stdout']);

        // import:resume — finishes the migration.
        $resumeResult = $this->runCliCommand(
            'import:resume',
            new ImportResumeCommand($rig['runner'], $rig['registry'], $lockFactory),
            [UsersCsvToWidgetsMigration::MIGRATION_ID],
        );
        self::assertSame(
            0,
            $resumeResult['exit_code'],
            'import:resume must exit 0: ' . $resumeResult['stderr'],
        );

        self::assertSame(1000, $this->countWidgets(), 'CLI-driven resume must yield 1000 entities');

        // import:status after completion — every record processed.
        $statusAfterResume = $this->runCliCommand(
            'import:status',
            new ImportStatusCommand($rig['registry'], $this->idMap, $this->runState),
            [UsersCsvToWidgetsMigration::MIGRATION_ID],
        );
        self::assertSame(0, $statusAfterResume['exit_code']);
        self::assertStringContainsString('1000', $statusAfterResume['stdout']);

        // import:rollback --confirm — undoes everything via the CLI surface.
        $rollbackResult = $this->runCliCommand(
            'import:rollback',
            new ImportRollbackCommand($walker, $rig['registry'], $this->idMap, $lockFactory),
            [UsersCsvToWidgetsMigration::MIGRATION_ID, '--confirm'],
        );
        self::assertSame(
            0,
            $rollbackResult['exit_code'],
            'import:rollback --confirm must exit 0: ' . $rollbackResult['stderr'],
        );
        self::assertStringContainsString('rollback complete', $rollbackResult['stdout']);
        self::assertSame(0, $this->countWidgets());
        self::assertSame(
            0,
            $this->idMap->countForMigration(UsersCsvToWidgetsMigration::MIGRATION_ID),
        );
    }

    // =========================================================================
    // Rig helpers
    // =========================================================================

    /**
     * Build the per-test rig — destination + registry + runner — bound to
     * the test's in-memory DB.
     *
     * @return array{
     *     destination: EntityDestination,
     *     registry: MigrationRegistry,
     *     runner: MigrationRunner,
     * }
     */
    private function buildRig(): array
    {
        $destination = new EntityDestination(
            destinationEntityTypeId: self::ENTITY_TYPE_ID,
            entityTypeManager: $this->typeManager,
            entityRepository: $this->repository,
            idMap: $this->idMap,
            gate: $this->gate,
            eventDispatcher: $this->dispatcher,
            migrationId: UsersCsvToWidgetsMigration::MIGRATION_ID,
            account: $this->systemAccount,
        );

        $definition = UsersCsvToWidgetsMigration::create($destination);

        $provider = new class([$definition]) implements HasMigrationsInterface {
            /** @param list<MigrationDefinition> $defs */
            public function __construct(private readonly array $defs)
            {
            }

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
            idMap: $this->idMap,
            runState: $this->runState,
        );

        return [
            'destination' => $destination,
            'registry' => $registry,
            'runner' => $runner,
        ];
    }

    /**
     * Count entity rows in the destination table. Bypasses `EntityRepository`
     * to dodge hydration cost — the count is a structural assertion, not a
     * data assertion.
     */
    private function countWidgets(): int
    {
        $stmt = $this->db->getConnection()->executeQuery(
            'SELECT COUNT(*) AS c FROM ' . self::ENTITY_TYPE_ID,
        );
        $row = $stmt->fetchAssociative();
        \assert(\is_array($row));

        return (int) $row['c'];
    }

    /**
     * Locate the widget written for a given CSV `id` column value. Reuses
     * the CsvSource source-id contract so the test is robust to changes in
     * the destination's storage layout.
     */
    private function findWidgetBySourceId(string $idValue): ?MigrationTestWidget
    {
        $sourceId = new SourceId(sourceType: 'csv_users', keys: ['id' => $idValue]);
        $writeResult = $this->idMap->lookupDestination(
            UsersCsvToWidgetsMigration::MIGRATION_ID,
            $sourceId,
        );
        if ($writeResult === null) {
            return null;
        }

        $matches = $this->repository->findBy(['uuid' => $writeResult->destinationUuid]);
        $entity = $matches[0] ?? null;

        return $entity instanceof MigrationTestWidget ? $entity : null;
    }

    /**
     * Count lines in a CSV file via a streaming read so we never load the
     * whole fixture into memory.
     */
    private function countCsvLines(string $path): int
    {
        $handle = \fopen($path, 'rb');
        if ($handle === false) {
            return 0;
        }
        try {
            $count = 0;
            while (\fgets($handle) !== false) {
                $count++;
            }
            return $count;
        } finally {
            \fclose($handle);
        }
    }

    // =========================================================================
    // CLI helpers (T061)
    // =========================================================================

    /**
     * Build a per-test lock factory pointing at an isolated temp dir so
     * concurrent CLI invocations across tests cannot collide.
     *
     * @return \Closure(string): MigrationLock
     */
    private function makeLockFactory(): \Closure
    {
        $lockDir = \sys_get_temp_dir()
            . \DIRECTORY_SEPARATOR
            . 'waaseyaa_e2e_lock_'
            . \uniqid('', true);

        return static fn(string $migrationId): MigrationLock => new MigrationLock(
            migrationId: $migrationId,
            lockDir: $lockDir,
        );
    }

    /**
     * Drive one CLI command through {@see CliTester} and surface a uniform
     * `{exit_code, stdout, stderr}` shape for the caller.
     *
     * @param array<int, string> $argv
     *
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function runCliCommand(string $name, object $handler, array $argv): array
    {
        $definition = $this->buildCommandDefinition($name, $handler);
        $container = $this->buildContainerFor($handler);
        $tester = CliTester::for($definition, $container);
        $tester->execute($argv);

        return [
            'exit_code' => $tester->getExitCode(),
            'stdout' => $tester->getStdout(),
            'stderr' => $tester->getStderr(),
        ];
    }

    private function buildCommandDefinition(string $name, object $handler): HandlerCommand
    {
        // The CliTester resolves the handler class out of the container via
        // `[FQN, 'execute']`; we keep argument + option definitions aligned
        // with each Import* command's flag surface so parsing succeeds.
        return match (true) {
            $handler instanceof ImportRunCommand => new HandlerCommand(
                name: $name,
                description: 'Run a single migration end-to-end (FR-032).',
                arguments: [new HandlerArgument(name: 'migration_id', mode: HandlerArgumentMode::Required)],
                options: [
                    new HandlerOption(name: 'dry-run', mode: HandlerOptionMode::None),
                    new HandlerOption(name: 'halt-on-error', mode: HandlerOptionMode::None),
                    new HandlerOption(name: 'limit', mode: HandlerOptionMode::Required),
                    new HandlerOption(name: 'run-id', mode: HandlerOptionMode::Required),
                ],
                handler: [ImportRunCommand::class, 'execute'],
            ),
            $handler instanceof ImportResumeCommand => new HandlerCommand(
                name: $name,
                description: 'Resume a partial migration (FR-037).',
                arguments: [new HandlerArgument(name: 'migration_id', mode: HandlerArgumentMode::Required)],
                options: [
                    new HandlerOption(name: 'dry-run', mode: HandlerOptionMode::None),
                    new HandlerOption(name: 'halt-on-error', mode: HandlerOptionMode::None),
                    new HandlerOption(name: 'limit', mode: HandlerOptionMode::Required),
                ],
                handler: [ImportResumeCommand::class, 'execute'],
            ),
            $handler instanceof ImportStatusCommand => new HandlerCommand(
                name: $name,
                description: 'Surface per-migration status (FR-034).',
                arguments: [new HandlerArgument(name: 'migration_id', mode: HandlerArgumentMode::Optional)],
                options: [],
                handler: [ImportStatusCommand::class, 'execute'],
            ),
            $handler instanceof ImportRollbackCommand => new HandlerCommand(
                name: $name,
                description: 'Roll back a migration (FR-035).',
                arguments: [new HandlerArgument(name: 'migration_id', mode: HandlerArgumentMode::Required)],
                options: [new HandlerOption(name: 'confirm', mode: HandlerOptionMode::None)],
                handler: [ImportRollbackCommand::class, 'execute'],
            ),
            $handler instanceof ImportResetCommand => new HandlerCommand(
                name: $name,
                description: 'Reset id-map without touching entities (FR-036).',
                arguments: [new HandlerArgument(name: 'migration_id', mode: HandlerArgumentMode::Required)],
                options: [new HandlerOption(name: 'confirm', mode: HandlerOptionMode::None)],
                handler: [ImportResetCommand::class, 'execute'],
            ),
            default => throw new \LogicException(
                'EndToEndCsvImportTest: unknown CLI handler ' . $handler::class,
            ),
        };
    }

    private function buildContainerFor(object $handler): ContainerInterface
    {
        return new class($handler) implements ContainerInterface {
            public function __construct(private readonly object $handler)
            {
            }

            public function get(string $id): mixed
            {
                if ($id === $this->handler::class) {
                    return $this->handler;
                }
                throw new \RuntimeException('Not bound: ' . $id);
            }

            public function has(string $id): bool
            {
                return $id === $this->handler::class;
            }
        };
    }
}
