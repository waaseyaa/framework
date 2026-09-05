<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Migration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Migration\LegacyReversePlanCatalog;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\Migrator;
use Waaseyaa\Foundation\Migration\SchemaBuilder;
use Waaseyaa\Foundation\Migration\TableBuilder;

/**
 * #2731 — fail-closed legacy reverse-plan contract.
 */
#[CoversClass(Migrator::class)]
#[CoversClass(LegacyReversePlanCatalog::class)]
final class LegacyReversePlanContractTest extends TestCase
{
    private \Doctrine\DBAL\Connection $connection;
    private SchemaBuilder $schema;
    private MigrationRepository $repository;
    private Migrator $migrator;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->schema = new SchemaBuilder($this->connection);
        $this->repository = new MigrationRepository($this->connection);
        $this->repository->createTable();
        $this->migrator = new Migrator($this->connection, $this->repository);
    }

    #[Test]
    public function rollback_refuses_noop_down_override_and_preserves_schema_and_ledger(): void
    {
        $migration = new class extends Migration {
            public function up(SchemaBuilder $schema): void
            {
                $schema->create('noop_reverse_probe', function (TableBuilder $table): void {
                    $table->id();
                });
            }

            public function down(SchemaBuilder $schema): void
            {
                // Intentionally empty — mirrors first-party forward-only overrides.
            }
        };

        $catalogue = ['app' => ['app:2731_noop_reverse' => $migration]];
        $this->migrator->run($catalogue);
        self::assertTrue($this->schema->hasTable('noop_reverse_probe'));
        self::assertTrue($this->repository->hasRun('app:2731_noop_reverse'));

        try {
            $this->migrator->rollback($catalogue);
            self::fail('No-op down() override must refuse, not report success.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('[S1-DB104]', $exception->getMessage());
        }

        self::assertTrue($this->schema->hasTable('noop_reverse_probe'));
        self::assertTrue($this->repository->hasRun('app:2731_noop_reverse'));
        self::assertSame(1, $this->migrator->status($catalogue)['completed'][0]['batch'] ?? null);
    }

    #[Test]
    public function rollback_refuses_changed_source_under_the_same_id(): void
    {
        $applied = new class extends Migration {
            public function up(SchemaBuilder $schema): void
            {
                $schema->create('changed_source_probe', function (TableBuilder $table): void {
                    $table->id();
                });
            }

            public function providesSupportedReverse(): bool
            {
                return true;
            }

            public function down(SchemaBuilder $schema): void
            {
                $schema->dropIfExists('changed_source_probe');
            }
        };

        $this->migrator->run(['app' => ['app:2731_changed_source' => $applied]]);

        $impostor = new class extends Migration {
            public function up(SchemaBuilder $schema): void
            {
                $schema->create('changed_source_probe', function (TableBuilder $table): void {
                    $table->id();
                });
            }

            public function providesSupportedReverse(): bool
            {
                return true;
            }

            public function down(SchemaBuilder $schema): void
            {
                $schema->dropIfExists('changed_source_probe');
                // Distinct body so the executable checksum diverges from the applied source.
            }
        };

        try {
            $this->migrator->rollback(['app' => ['app:2731_changed_source' => $impostor]]);
            self::fail('Changed reverse source under an applied id must refuse.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('[S1-DB113]', $exception->getMessage());
        }

        self::assertTrue($this->schema->hasTable('changed_source_probe'));
        self::assertTrue($this->repository->hasRun('app:2731_changed_source'));
    }

    #[Test]
    public function rollback_refuses_package_substitution_for_an_applied_id(): void
    {
        $migration = new class extends Migration {
            public function up(SchemaBuilder $schema): void
            {
                $schema->create('package_probe', function (TableBuilder $table): void {
                    $table->id();
                });
            }

            public function providesSupportedReverse(): bool
            {
                return true;
            }

            public function down(SchemaBuilder $schema): void
            {
                $schema->dropIfExists('package_probe');
            }
        };

        $this->migrator->run(['app' => ['app:2731_package' => $migration]]);

        try {
            $this->migrator->rollback(['other' => ['app:2731_package' => $migration]]);
            self::fail('Package substitution on rollback must refuse.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('[S1-DB113]', $exception->getMessage());
        }

        self::assertTrue($this->schema->hasTable('package_probe'));
        self::assertTrue($this->repository->hasRun('app:2731_package'));
    }

    #[Test]
    public function rollback_refuses_when_ledger_checksum_is_null(): void
    {
        $migration = new class extends Migration {
            public function up(SchemaBuilder $schema): void {}

            public function providesSupportedReverse(): bool
            {
                return true;
            }

            public function down(SchemaBuilder $schema): void
            {
                $schema->dropIfExists('never_created');
            }
        };

        $this->repository->record('app:2731_null_checksum', 'app', 1);

        try {
            $this->migrator->rollback(['app' => ['app:2731_null_checksum' => $migration]]);
            self::fail('Null ledger checksum must refuse reverse as unverifiable.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('[S1-DB113]', $exception->getMessage());
        }

        self::assertTrue($this->repository->hasRun('app:2731_null_checksum'));
    }

    #[Test]
    public function rollback_prefights_the_whole_batch_before_any_reverse_mutation(): void
    {
        $supported = new class extends Migration {
            public function up(SchemaBuilder $schema): void
            {
                $schema->create('batch_first', function (TableBuilder $table): void {
                    $table->id();
                });
            }

            public function providesSupportedReverse(): bool
            {
                return true;
            }

            public function down(SchemaBuilder $schema): void
            {
                $schema->dropIfExists('batch_first');
            }
        };

        $unsupported = new class extends Migration {
            public function up(SchemaBuilder $schema): void
            {
                $schema->create('batch_second', function (TableBuilder $table): void {
                    $table->id();
                });
            }

            public function down(SchemaBuilder $schema): void
            {
                // Empty override — looks reversible under the old heuristic.
            }
        };

        $catalogue = ['app' => [
            'app:2731_batch_first' => $supported,
            'app:2731_batch_second' => $unsupported,
        ]];
        $this->migrator->run($catalogue);

        try {
            $this->migrator->rollback($catalogue);
            self::fail('Batch with an unsupported later node must refuse entirely.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('[S1-DB104]', $exception->getMessage());
        }

        self::assertTrue($this->schema->hasTable('batch_first'));
        self::assertTrue($this->schema->hasTable('batch_second'));
        self::assertTrue($this->repository->hasRun('app:2731_batch_first'));
        self::assertTrue($this->repository->hasRun('app:2731_batch_second'));
    }

    #[Test]
    public function rollback_refuses_ineffective_opted_in_reverse_post_state(): void
    {
        $migration = new class extends Migration {
            public function up(SchemaBuilder $schema): void
            {
                $schema->create('ineffective_reverse', function (TableBuilder $table): void {
                    $table->id();
                });
            }

            public function providesSupportedReverse(): bool
            {
                return true;
            }

            public function down(SchemaBuilder $schema): void
            {
                // Opted in but does not reverse schema — must fail post-state.
            }
        };

        $catalogue = ['app' => ['app:2731_ineffective' => $migration]];
        $this->migrator->run($catalogue);

        try {
            $this->migrator->rollback($catalogue);
            self::fail('Ineffective opted-in reverse must refuse post-state.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('[S1-DB114]', $exception->getMessage());
        }

        self::assertTrue($this->schema->hasTable('ineffective_reverse'));
        self::assertTrue($this->repository->hasRun('app:2731_ineffective'));
    }

    #[Test]
    public function supported_reverse_still_drops_schema_and_ledger(): void
    {
        $migration = new class extends Migration {
            public function up(SchemaBuilder $schema): void
            {
                $schema->create('supported_reverse', function (TableBuilder $table): void {
                    $table->id();
                });
            }

            public function providesSupportedReverse(): bool
            {
                return true;
            }

            public function down(SchemaBuilder $schema): void
            {
                $schema->dropIfExists('supported_reverse');
            }
        };

        $catalogue = ['app' => ['app:2731_supported' => $migration]];
        $this->migrator->run($catalogue);
        $result = $this->migrator->rollback($catalogue);

        self::assertSame(1, $result->count);
        self::assertFalse($this->schema->hasTable('supported_reverse'));
        self::assertFalse($this->repository->hasRun('app:2731_supported'));
    }
}
