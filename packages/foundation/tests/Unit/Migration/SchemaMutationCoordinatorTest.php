<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Migration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Migration\LogicalSchemaFingerprint;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\SchemaMutationCoordinator;

#[CoversClass(SchemaMutationCoordinator::class)]
final class SchemaMutationCoordinatorTest extends TestCase
{
    #[Test]
    public function authority_ledger_and_schema_effect_share_one_transaction(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $coordinator = new SchemaMutationCoordinator(
            $connection,
            new MigrationRepository($connection),
        );

        try {
            $coordinator->execute(function () use ($connection): never {
                self::assertTrue($connection->isTransactionActive());
                $connection->executeStatement('CREATE TABLE must_roll_back (id INTEGER PRIMARY KEY)');
                throw new \RuntimeException('injected coordinator failure');
            });
            self::fail('Injected coordinator failure did not escape.');
        } catch (\RuntimeException $exception) {
            self::assertSame('injected coordinator failure', $exception->getMessage());
        }

        $tables = $connection->executeQuery(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
        )->fetchFirstColumn();
        self::assertSame([], $tables);
    }

    #[Test]
    public function successful_transition_returns_its_result_after_installing_authority(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $coordinator = new SchemaMutationCoordinator(
            $connection,
            new MigrationRepository($connection),
        );

        $result = $coordinator->execute(static fn(): string => 'committed');

        self::assertSame('committed', $result);
        self::assertTrue($connection->createSchemaManager()->tablesExist([
            'waaseyaa_schema_authority',
            'waaseyaa_migrations',
        ]));
        $manifest = new MigrationRepository($connection)->schemaAuthorityManifest();
        self::assertNotNull($manifest);
        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT generation FROM waaseyaa_schema_authority WHERE authority_id = 1',
        ));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $manifest->schemaFingerprint ?? '');
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $manifest->ledgerFingerprint ?? '');
        self::assertNull($manifest->sourceCatalogFingerprint);
    }

    #[Test]
    public function nested_executor_on_the_same_connection_uses_one_authority_generation(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $outer = new SchemaMutationCoordinator($connection, new MigrationRepository($connection));
        $inner = new SchemaMutationCoordinator($connection, new MigrationRepository($connection));

        $outer->execute(function () use ($connection, $inner): void {
            self::assertTrue(SchemaMutationCoordinator::isActive($connection));
            $inner->execute(function () use ($connection): void {
                self::assertTrue(SchemaMutationCoordinator::isActive($connection));
                $connection->executeStatement('CREATE TABLE nested_entity_schema (id INTEGER PRIMARY KEY)');
            });
        });

        self::assertFalse(SchemaMutationCoordinator::isActive($connection));
        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT generation FROM waaseyaa_schema_authority WHERE authority_id = 1',
        ));
        self::assertTrue($connection->createSchemaManager()->tablesExist(['nested_entity_schema']));
    }
    /**
     * #2730: the manifest proves an authorised transition. A transition that
     * finds the live schema differing from the recorded manifest must refuse
     * before touching anything, not adopt whatever is present.
     */
    #[Test]
    public function refuses_a_transition_when_live_schema_differs_from_the_recorded_manifest(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $repository = new MigrationRepository($connection);
        $coordinator = new SchemaMutationCoordinator($connection, $repository);
        $coordinator->execute(static function () use ($connection): void {
            $connection->executeStatement('CREATE TABLE sample (id INTEGER PRIMARY KEY)');
        });
        $manifest = $repository->schemaAuthorityManifest();
        self::assertNotNull($manifest);

        $connection->executeStatement('ALTER TABLE sample ADD COLUMN unauthorised TEXT');

        $refusal = null;
        try {
            $coordinator->execute(static function () use ($connection): void {
                $connection->executeStatement('CREATE TABLE must_not_exist (id INTEGER PRIMARY KEY)');
            });
        } catch (\RuntimeException $exception) {
            $refusal = $exception;
        }
        self::assertNotNull($refusal, 'A drifted live schema did not refuse the transition.');
        self::assertStringContainsString('[S1-DB109]', $refusal->getMessage());

        self::assertFalse(SchemaMutationCoordinator::isActive($connection));
        self::assertFalse($connection->createSchemaManager()->tablesExist(['must_not_exist']));
        self::assertEquals($manifest, $repository->schemaAuthorityManifest(), 'refusal leaves the manifest unchanged');
        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT generation FROM waaseyaa_schema_authority WHERE authority_id = 1',
        ), 'refusal leaves the authority generation unchanged');
        self::assertNotSame(
            $manifest->schemaFingerprint,
            $repository->currentLogicalSchemaFingerprint(),
            'the drift stays visible to verification',
        );
    }

    #[Test]
    public function refuses_a_transition_when_ledger_rows_differ_from_the_recorded_manifest(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $repository = new MigrationRepository($connection);
        $coordinator = new SchemaMutationCoordinator($connection, $repository);
        $coordinator->execute(static function () use ($repository): void {
            $repository->record('waaseyaa/test:one', 'waaseyaa/test', 1, str_repeat('a', 64), str_repeat('b', 64));
        });
        $manifest = $repository->schemaAuthorityManifest();
        self::assertNotNull($manifest);

        $connection->executeStatement('UPDATE waaseyaa_migrations SET batch = 99');

        $refusal = null;
        try {
            $coordinator->execute(static fn(): null => null);
        } catch (\RuntimeException $exception) {
            $refusal = $exception;
        }
        self::assertNotNull($refusal, 'A drifted ledger did not refuse the transition.');
        self::assertStringContainsString('[S1-DB109]', $refusal->getMessage());

        self::assertEquals($manifest, $repository->schemaAuthorityManifest(), 'refusal leaves the manifest unchanged');
        self::assertSame(99, (int) $connection->fetchOne('SELECT batch FROM waaseyaa_migrations'), 'refusal changes no ledger row');
        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT generation FROM waaseyaa_schema_authority WHERE authority_id = 1',
        ));
    }

    /**
     * #2452 adoption: an install without authority records is a governed
     * adoption, not drift — the next real mutation records the manifest.
     */
    #[Test]
    public function a_transition_without_a_recorded_manifest_adopts_the_live_schema(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $repository = new MigrationRepository($connection);
        $coordinator = new SchemaMutationCoordinator($connection, $repository);
        $coordinator->execute(static function () use ($connection): void {
            $connection->executeStatement('CREATE TABLE widget (id INTEGER PRIMARY KEY)');
        });
        $schema = $connection->createSchemaManager();
        $schema->dropTable('waaseyaa_schema_authority');
        $schema->dropTable('waaseyaa_migrations');
        $connection->executeStatement('CREATE TABLE gadget (id INTEGER PRIMARY KEY)');

        $coordinator->execute(static function () use ($connection): void {
            $connection->executeStatement('CREATE TABLE gizmo (id INTEGER PRIMARY KEY)');
        });

        $manifest = $repository->schemaAuthorityManifest();
        self::assertNotNull($manifest);
        self::assertSame($repository->currentLogicalSchemaFingerprint(), $manifest->schemaFingerprint);
        self::assertSame($repository->currentLedgerFingerprint(), $manifest->ledgerFingerprint);
        self::assertTrue($connection->createSchemaManager()->tablesExist(['gadget', 'gizmo']));
    }

    /**
     * Pre-#2701 installs recorded a manifest while the ledger still lacked
     * `apply_mode`. The ledger upgrade happens inside the boundary after the
     * pre-state check, so an unchanged schema still transitions.
     */
    #[Test]
    public function a_ledger_upgrade_inside_the_boundary_is_not_treated_as_drift(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE waaseyaa_migrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            migration VARCHAR(255) NOT NULL,
            package VARCHAR(128) NOT NULL,
            batch INTEGER NOT NULL,
            ran_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            checksum VARCHAR(64) NULL,
            diff_hash VARCHAR(64) NULL
        )');
        $connection->executeStatement(
            'CREATE UNIQUE INDEX waaseyaa_migrations_migration_unique ON waaseyaa_migrations (migration)',
        );
        $connection->executeStatement('CREATE TABLE waaseyaa_schema_authority (
            authority_id INTEGER PRIMARY KEY CHECK (authority_id = 1),
            generation INTEGER NOT NULL,
            schema_fingerprint VARCHAR(64) NULL,
            ledger_fingerprint VARCHAR(64) NULL,
            source_catalog_fingerprint VARCHAR(64) NULL
        )');
        $connection->executeStatement('INSERT INTO waaseyaa_schema_authority (authority_id, generation) VALUES (1, 1)');
        $connection->executeStatement('CREATE TABLE sample (id INTEGER PRIMARY KEY)');
        $repository = new MigrationRepository($connection);
        $repository->recordSchemaManifest(LogicalSchemaFingerprint::capture($connection));

        $result = new SchemaMutationCoordinator($connection, $repository)->execute(static fn(): string => 'upgraded');

        self::assertSame('upgraded', $result);
        self::assertContains('apply_mode', array_column(
            $connection->executeQuery('PRAGMA table_info(waaseyaa_migrations)')->fetchAllAssociative(),
            'name',
        ));
        $manifest = $repository->schemaAuthorityManifest();
        self::assertNotNull($manifest);
        self::assertSame($repository->currentLogicalSchemaFingerprint(), $manifest->schemaFingerprint);
    }
}
