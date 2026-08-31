<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Migration\Ledger;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Migration\Executor\V2PlanExecutor;
use Waaseyaa\Foundation\Migration\LedgerRow;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\MigrationCatalogFingerprint;
use Waaseyaa\Foundation\Migration\LedgerSchema\V2_0002_add_apply_mode_column;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\Migrator;
use Waaseyaa\Foundation\Migration\SchemaBuilder;
use Waaseyaa\Foundation\Migration\TableBuilder;
use Waaseyaa\Foundation\Schema\Compiler\Sqlite\SqliteCompiler;
use Waaseyaa\Foundation\Schema\Diff\AddColumn;
use Waaseyaa\Foundation\Schema\Diff\ColumnSpec;
use Waaseyaa\Foundation\Schema\Diff\CompositeDiff;
use Waaseyaa\Foundation\Schema\Migration\MigrationInterfaceV2;
use Waaseyaa\Foundation\Schema\Migration\MigrationPlan;

#[CoversClass(MigrationRepository::class)]
#[CoversClass(Migrator::class)]
#[CoversClass(V2_0002_add_apply_mode_column::class)]
final class ChecksumWriteTest extends TestCase
{
    #[Test]
    public function v2ApplyWritesChecksumAndDiffHash(): void
    {
        [$connection, $repo, $migrator] = self::buildHarness();
        $connection->executeStatement('CREATE TABLE widgets (id INTEGER PRIMARY KEY)');

        $v2 = self::v2('waaseyaa/test:v2:add-archived', new CompositeDiff([
            new AddColumn('widgets', 'archived_at', new ColumnSpec(type: 'int', nullable: true)),
        ]));

        $migrator->run([], [$v2]);

        /** @var list<LedgerRow> $rows */
        $rows = $repo->allWithChecksums();
        self::assertCount(1, $rows);

        self::assertSame('waaseyaa/test:v2:add-archived', $rows[0]->migration);
        self::assertNotNull($rows[0]->checksum);
        self::assertSame(64, strlen($rows[0]->checksum));
        self::assertNotNull($rows[0]->diffHash);
        self::assertSame(64, strlen($rows[0]->diffHash));

        // Checksum = source intent SHA, diff_hash = compiled-plan SHA.
        // They differ because the source carries no SQL.
        self::assertNotSame($rows[0]->checksum, $rows[0]->diffHash);
    }

    #[Test]
    public function legacyApplyWritesExactSourceAndProceduralPlanHashes(): void
    {
        [, $repo, $migrator] = self::buildHarness();
        $legacy = new class extends Migration {
            public function up(SchemaBuilder $schema): void
            {
                $schema->create('users', static function (TableBuilder $table) {
                    $table->id();
                });
            }
        };

        $migrator->run(['waaseyaa/test' => ['waaseyaa/test:001_create_users' => $legacy]]);

        $rows = $repo->allWithChecksums();
        self::assertCount(1, $rows);
        $sourceChecksum = MigrationCatalogFingerprint::legacySourceChecksum($legacy);
        self::assertSame($sourceChecksum, $rows[0]->checksum);
        self::assertSame(MigrationCatalogFingerprint::legacyPlanHash($sourceChecksum), $rows[0]->diffHash);
    }

    #[Test]
    public function emptyV2PlanStillRecordsBothHashes(): void
    {
        // Per spec §15 Q3, empty plans are valid applies. The compiled
        // plan's diff_hash is the SHA-256 of `{"steps":[]}` — a stable
        // fingerprint that verify mode can still match on.
        [, $repo, $migrator] = self::buildHarness();
        $v2 = self::v2('waaseyaa/test:v2:noop', CompositeDiff::empty());

        $migrator->run([], [$v2]);

        $rows = $repo->allWithChecksums();
        self::assertCount(1, $rows);
        self::assertNotNull($rows[0]->checksum);
        self::assertNotNull($rows[0]->diffHash);
    }

    #[Test]
    public function ensureCurrentSchemaUpgradesPreWp09Table(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        // Simulate a pre-WP09 ledger table (no checksum / diff_hash columns).
        $connection->executeStatement(
            'CREATE TABLE waaseyaa_migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration VARCHAR(255) NOT NULL,
                package VARCHAR(128) NOT NULL,
                batch INTEGER NOT NULL,
                ran_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
        );
        $connection->executeStatement(
            'INSERT INTO waaseyaa_migrations (migration, package, batch) VALUES (?, ?, ?)',
            ['legacy/foo:001_init', 'legacy/foo', 1],
        );

        (new MigrationRepository($connection))->ensureCurrentSchema();

        $columns = array_column(
            $connection->executeQuery('PRAGMA table_info(waaseyaa_migrations)')->fetchAllAssociative(),
            'name',
        );
        self::assertContains('checksum', $columns);
        self::assertContains('diff_hash', $columns);
        // FW-2701 extends the same upgrade path with the apply_mode audit column.
        self::assertContains('apply_mode', $columns);

        // Existing pre-WP09 row survives the migration with null hashes.
        $row = $connection->executeQuery('SELECT checksum, diff_hash, apply_mode FROM waaseyaa_migrations')->fetchAssociative();
        self::assertNotFalse($row);
        self::assertNull($row['checksum']);
        self::assertNull($row['diff_hash']);
        self::assertNull($row['apply_mode'], 'rows written before FW-2701 carry no apply mode');
    }

    #[Test]
    public function ensureCurrentSchemaUpgradesAPreFw2701LedgerAndKeepsRecordingUsable(): void
    {
        // A site that already ran WP09 has checksum/diff_hash but not apply_mode.
        // Without the upgrade its first record() would fail on "no such column".
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(
            'CREATE TABLE waaseyaa_migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration VARCHAR(255) NOT NULL,
                package VARCHAR(128) NOT NULL,
                batch INTEGER NOT NULL,
                ran_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                checksum VARCHAR(64) NULL,
                diff_hash VARCHAR(64) NULL
            )',
        );
        $connection->executeStatement(
            'INSERT INTO waaseyaa_migrations (migration, package, batch, checksum) VALUES (?, ?, ?, ?)',
            ['waaseyaa/foundation:v2:earlier', 'waaseyaa/foundation', 1, str_repeat('a', 64)],
        );

        // The production upgrade path a real site runs, which also installs the
        // canonical identity constraint the ledger readers require.
        $repository = new MigrationRepository($connection);
        $repository->installOrUpgradeLedger();

        self::assertContains('apply_mode', array_column(
            $connection->executeQuery('PRAGMA table_info(waaseyaa_migrations)')->fetchAllAssociative(),
            'name',
        ));
        self::assertNull(
            $repository->getApplyMode('waaseyaa/foundation:v2:earlier'),
            'a row written before this upgrade reports no apply mode',
        );

        $repository->record(
            'waaseyaa/foundation:v2:later',
            'waaseyaa/foundation',
            2,
            str_repeat('b', 64),
            str_repeat('c', 64),
            'already_satisfied',
        );

        self::assertSame('already_satisfied', $repository->getApplyMode('waaseyaa/foundation:v2:later'));
    }

    #[Test]
    public function theLedgerSchemaAuthoringRecordDocumentsTheApplyModeColumn(): void
    {
        // The authoring record is what audit tooling and verify mode read to
        // answer "what does the ledger schema look like at v2.0002".
        $plan = new V2_0002_add_apply_mode_column()->plan();

        self::assertSame('waaseyaa/foundation:v2:ledger-add-apply-mode-column', $plan->migrationId);
        self::assertSame(
            ['waaseyaa/foundation:v2:ledger-add-checksum-columns'],
            $plan->dependencies,
            'it must order after the WP09 columns it extends',
        );
        self::assertSame(
            [[
                'column' => 'apply_mode',
                'kind' => 'add_column',
                'spec' => ['default' => null, 'length' => 32, 'nullable' => true, 'type' => 'varchar'],
                'table' => 'waaseyaa_migrations',
            ]],
            $plan->toCanonical()['ops'],
        );
    }

    /**
     * @return array{0: \Doctrine\DBAL\Connection, 1: MigrationRepository, 2: Migrator}
     */
    private static function buildHarness(): array
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $repo = new MigrationRepository($connection);
        $repo->createTable();

        $migrator = new Migrator(
            $connection,
            $repo,
            new V2PlanExecutor($connection, SqliteCompiler::forVersion('3.40.0')),
        );

        return [$connection, $repo, $migrator];
    }

    private static function v2(string $id, CompositeDiff $root): MigrationInterfaceV2
    {
        return new class ($id, $root) implements MigrationInterfaceV2 {
            public function __construct(
                private readonly string $id,
                private readonly CompositeDiff $root,
            ) {}
            public function migrationId(): string
            {
                return $this->id;
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
                    migrationId: $this->id,
                    package: 'waaseyaa/test',
                    dependencies: [],
                    root: $this->root,
                );
            }
        };
    }
}
