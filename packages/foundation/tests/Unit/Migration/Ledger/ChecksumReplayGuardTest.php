<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Migration\Ledger;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Migration\ChecksumMismatchException;
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

#[CoversClass(Migrator::class)]
#[CoversClass(ChecksumMismatchException::class)]
final class ChecksumReplayGuardTest extends TestCase
{
    #[Test]
    public function reapplyWithSameChecksumIsSilentNoOp(): void
    {
        [$connection, $repo] = self::createConnectionAndRepo();
        $connection->executeStatement('CREATE TABLE widgets (id INTEGER PRIMARY KEY)');

        $migrator = self::migrator($connection, $repo, isProduction: true);

        $v2 = self::v2('waaseyaa/test:v2:foo', new CompositeDiff([
            new AddColumn('widgets', 'archived_at', new ColumnSpec(type: 'int', nullable: true)),
        ]));

        // First apply succeeds.
        $migrator->run([], [$v2]);

        // Second apply with the same plan: no-op, no exception.
        $result = $migrator->run([], [$v2]);
        self::assertSame(0, $result->count);
    }

    #[Test]
    public function productionThrowsOnChecksumMismatch(): void
    {
        [$connection, $repo] = self::createConnectionAndRepo();
        $connection->executeStatement('CREATE TABLE widgets (id INTEGER PRIMARY KEY)');

        $migrator = self::migrator($connection, $repo, isProduction: true);

        $original = self::v2('waaseyaa/test:v2:foo', new CompositeDiff([
            new AddColumn('widgets', 'archived_at', new ColumnSpec(type: 'int', nullable: true)),
        ]));
        $migrator->run([], [$original]);

        // Same migration_id, different structural intent.
        $drifted = self::v2('waaseyaa/test:v2:foo', new CompositeDiff([
            new AddColumn('widgets', 'deleted_at', new ColumnSpec(type: 'int', nullable: true)),
        ]));

        $thrown = null;
        try {
            $migrator->run([], [$drifted]);
        } catch (ChecksumMismatchException $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown);
        self::assertSame('CHECKSUM_MISMATCH', $thrown->diagnosticCode());
        self::assertSame('waaseyaa/test:v2:foo', $thrown->migration);
        self::assertNotSame($thrown->stored, $thrown->computed);
    }

    #[Test]
    public function developmentLogsWarningInsteadOfThrowing(): void
    {
        [$connection, $repo] = self::createConnectionAndRepo();
        $connection->executeStatement('CREATE TABLE widgets (id INTEGER PRIMARY KEY)');

        $logger = self::recordingLogger();

        $migrator = self::migrator($connection, $repo, isProduction: false, logger: $logger);

        $original = self::v2('waaseyaa/test:v2:foo', new CompositeDiff([
            new AddColumn('widgets', 'archived_at', new ColumnSpec(type: 'int', nullable: true)),
        ]));
        $migrator->run([], [$original]);

        $drifted = self::v2('waaseyaa/test:v2:foo', new CompositeDiff([
            new AddColumn('widgets', 'deleted_at', new ColumnSpec(type: 'int', nullable: true)),
        ]));

        // Should NOT throw in dev mode.
        $result = $migrator->run([], [$drifted]);
        self::assertSame(0, $result->count);

        // Warning was logged.
        self::assertNotEmpty($logger->records);
        $warnings = array_filter($logger->records, static fn(array $r): bool => $r['level'] === LogLevel::WARNING);
        self::assertNotEmpty($warnings);
        $messages = array_column($warnings, 'message');
        self::assertStringContainsString('waaseyaa/test:v2:foo', implode("\n", $messages));
    }


    /**
     * #2730: a legacy node skipped on `hasRun()` alone let a changed body under
     * the same id replay as a count-zero success that rewrote the catalogue
     * authority. Replay rechecks the stored source checksum for both kinds.
     */
    #[Test]
    public function productionThrowsWhenAnAppliedLegacySourceChangesUnderTheSameId(): void
    {
        [$connection, $repo] = self::createConnectionAndRepo();
        $migrator = self::migrator($connection, $repo, isProduction: true);
        $original = ['waaseyaa/test' => ['waaseyaa/test:legacy' => self::legacyCreating('legacy_widgets')]];
        $migrator->run($original);
        $recorded = $repo->schemaAuthorityManifest();
        self::assertNotNull($recorded);
        self::assertSame(MigrationCatalogFingerprint::capture($original, []), $recorded->sourceCatalogFingerprint);

        $changed = ['waaseyaa/test' => ['waaseyaa/test:legacy' => new class extends Migration {
            public function up(SchemaBuilder $schema): void
            {
                $schema->create('legacy_widgets', static function ($table): void {
                    $table->id();
                    $table->string('rewritten');
                });
            }
        }]];

        $thrown = null;
        try {
            $migrator->run($changed);
        } catch (ChecksumMismatchException $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown);
        self::assertSame('waaseyaa/test:legacy', $thrown->migration);
        self::assertSame(
            MigrationCatalogFingerprint::capture($original, []),
            $repo->schemaAuthorityManifest()?->sourceCatalogFingerprint,
            'a refused replay must not rewrite the catalogue authority',
        );
    }

    #[Test]
    public function developmentWarnsWhenAnAppliedLegacySourceChangesUnderTheSameId(): void
    {
        [$connection, $repo] = self::createConnectionAndRepo();
        $logger = self::recordingLogger();
        $migrator = self::migrator($connection, $repo, isProduction: false, logger: $logger);
        $migrator->run(['waaseyaa/test' => ['waaseyaa/test:legacy' => self::legacyCreating('legacy_widgets')]]);

        $result = $migrator->run(['waaseyaa/test' => ['waaseyaa/test:legacy' => new class extends Migration {
            public function up(SchemaBuilder $schema): void
            {
                $schema->create('legacy_widgets', static function ($table): void {
                    $table->id();
                    $table->string('rewritten_in_dev');
                });
            }
        }]]);

        self::assertSame(0, $result->count);
        $warnings = array_filter($logger->records, static fn(array $r): bool => $r['level'] === LogLevel::WARNING);
        self::assertStringContainsString('waaseyaa/test:legacy', implode("\n", array_column($warnings, 'message')));
    }

    #[Test]
    public function productionRefusesAReplayWhoseLedgerPackageDiffers(): void
    {
        [$connection, $repo] = self::createConnectionAndRepo();
        $connection->executeStatement('CREATE TABLE widgets (id INTEGER PRIMARY KEY)');
        $v2 = self::v2('waaseyaa/test:v2:foo', new CompositeDiff([
            new AddColumn('widgets', 'archived_at', new ColumnSpec(type: 'int', nullable: true)),
        ]));
        $compiled = SqliteCompiler::forVersion('3.40.0')->compile($v2->plan()->root)->diffHash();
        $repo->record('waaseyaa/test:v2:foo', 'waaseyaa/other-package', 1, $v2->plan()->checksum(), $compiled);

        $thrown = null;
        try {
            self::migrator($connection, $repo, isProduction: true)->run([], [$v2]);
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown);
        self::assertStringContainsString('[S1-DB112]', $thrown->getMessage());
        self::assertStringContainsString('waaseyaa/other-package', $thrown->getMessage());
    }

    #[Test]
    public function productionRefusesAReplayWhoseCompiledPlanDiffers(): void
    {
        [$connection, $repo] = self::createConnectionAndRepo();
        $connection->executeStatement('CREATE TABLE widgets (id INTEGER PRIMARY KEY)');
        $v2 = self::v2('waaseyaa/test:v2:foo', new CompositeDiff([
            new AddColumn('widgets', 'archived_at', new ColumnSpec(type: 'int', nullable: true)),
        ]));
        $repo->record('waaseyaa/test:v2:foo', 'waaseyaa/test', 1, $v2->plan()->checksum(), str_repeat('0', 64));

        $thrown = null;
        try {
            self::migrator($connection, $repo, isProduction: true)->run([], [$v2]);
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown);
        self::assertStringContainsString('[S1-DB112]', $thrown->getMessage());
        self::assertStringContainsString('compiled plan', $thrown->getMessage());
    }

    #[Test]
    public function developmentWarnsInsteadOfRefusingAPackageOrPlanMismatch(): void
    {
        [$connection, $repo] = self::createConnectionAndRepo();
        $connection->executeStatement('CREATE TABLE widgets (id INTEGER PRIMARY KEY)');
        $v2 = self::v2('waaseyaa/test:v2:foo', new CompositeDiff([
            new AddColumn('widgets', 'archived_at', new ColumnSpec(type: 'int', nullable: true)),
        ]));
        $repo->record('waaseyaa/test:v2:foo', 'waaseyaa/other-package', 1, $v2->plan()->checksum(), str_repeat('0', 64));
        $logger = self::recordingLogger();

        $result = self::migrator($connection, $repo, isProduction: false, logger: $logger)->run([], [$v2]);

        self::assertSame(0, $result->count);
        $warnings = array_column(
            array_filter($logger->records, static fn(array $r): bool => $r['level'] === LogLevel::WARNING),
            'message',
        );
        self::assertStringContainsString('[S1-DB112]', implode("\n", $warnings));
    }

    private static function legacyCreating(string $table): Migration
    {
        return new class ($table) extends Migration {
            public function __construct(private readonly string $table) {}

            public function up(SchemaBuilder $schema): void
            {
                $schema->create($this->table, static function ($table): void {
                    $table->id();
                });
            }
        };
    }

    /**
     * @return LoggerInterface&object{records: list<array{level: LogLevel, message: string}>}
     */
    private static function recordingLogger(): LoggerInterface
    {
        return new class implements LoggerInterface {
            /** @var list<array{level: LogLevel, message: string}> */
            public array $records = [];
            public function debug(string|\Stringable $message, array $context = []): void
            {
                $this->log(LogLevel::DEBUG, $message);
            }
            public function info(string|\Stringable $message, array $context = []): void
            {
                $this->log(LogLevel::INFO, $message);
            }
            public function notice(string|\Stringable $message, array $context = []): void
            {
                $this->log(LogLevel::NOTICE, $message);
            }
            public function warning(string|\Stringable $message, array $context = []): void
            {
                $this->log(LogLevel::WARNING, $message);
            }
            public function error(string|\Stringable $message, array $context = []): void
            {
                $this->log(LogLevel::ERROR, $message);
            }
            public function critical(string|\Stringable $message, array $context = []): void
            {
                $this->log(LogLevel::CRITICAL, $message);
            }
            public function alert(string|\Stringable $message, array $context = []): void
            {
                $this->log(LogLevel::ALERT, $message);
            }
            public function emergency(string|\Stringable $message, array $context = []): void
            {
                $this->log(LogLevel::EMERGENCY, $message);
            }
            public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message];
            }
        };
    }
    /**
     * @return array{0: \Doctrine\DBAL\Connection, 1: MigrationRepository}
     */
    private static function createConnectionAndRepo(): array
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $repo = new MigrationRepository($connection);
        $repo->createTable();

        return [$connection, $repo];
    }

    private static function migrator(
        \Doctrine\DBAL\Connection $connection,
        MigrationRepository $repo,
        bool $isProduction,
        ?LoggerInterface $logger = null,
    ): Migrator {
        return new Migrator(
            $connection,
            $repo,
            new V2PlanExecutor($connection, SqliteCompiler::forVersion('3.40.0')),
            $isProduction,
            $logger,
        );
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
