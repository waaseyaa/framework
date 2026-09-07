<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;
use Waaseyaa\Oidc\Exception\DuplicateClientIdException;

/**
 * #2766 — oidc_client.client_id must be a database-enforced unique registry
 * identity. This pins the migration that materializes it: it enforces a
 * fresh install, refuses (without deleting or merging anything) when
 * historical duplicates already exist, and is idempotent.
 */
#[CoversNothing]
final class OidcClientIdUniqueKeyMigrationTest extends TestCase
{
    #[Test]
    public function freshInstallMaterializesTheUniqueKeyAndTheDatabaseEnforcesIt(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema = new SchemaBuilder($connection);
        $this->applyBaseMigration($schema);

        $this->applyTargetMigration($schema);

        $indexes = $connection->createSchemaManager()->listTableIndexes('oidc_client');
        self::assertArrayHasKey('oidc_client_client_id_unique', $indexes);
        self::assertTrue($indexes['oidc_client_client_id_unique']->isUnique());

        $connection->insert('oidc_client', ['client_id' => 'minoo-web', 'uuid' => 'u1', 'name' => 'Minoo']);

        $this->expectDatabaseUniqueConstraintViolation();
        $connection->insert('oidc_client', ['client_id' => 'minoo-web', 'uuid' => 'u2', 'name' => 'Impersonator']);
    }

    #[Test]
    public function idempotentReapplicationIsANoOp(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema = new SchemaBuilder($connection);
        $this->applyBaseMigration($schema);
        $this->applyTargetMigration($schema);

        // Re-running must not throw and must not duplicate the index.
        $this->applyTargetMigration($schema);

        $indexes = $connection->createSchemaManager()->listTableIndexes('oidc_client');
        self::assertCount(1, array_filter(
            array_keys($indexes),
            static fn(string $name): bool => strtolower($name) === 'oidc_client_client_id_unique',
        ));
    }

    #[Test]
    public function existingDuplicatesRefuseMaterializationWithoutDeletingOrMergingAnything(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema = new SchemaBuilder($connection);
        $this->applyBaseMigration($schema);

        // Historical duplicates, exactly the reproduction shape from #2766:
        // two rows sharing client_id with different redirect authority.
        $connection->insert('oidc_client', [
            'client_id' => 'duplicate-client', 'uuid' => 'u1', 'name' => 'First',
        ]);
        $connection->insert('oidc_client', [
            'client_id' => 'duplicate-client', 'uuid' => 'u2', 'name' => 'Second',
        ]);
        $connection->insert('oidc_client', [
            'client_id' => 'unique-client', 'uuid' => 'u3', 'name' => 'Unrelated',
        ]);

        try {
            $this->applyTargetMigration($schema);
            self::fail('Expected DuplicateClientIdException.');
        } catch (DuplicateClientIdException $exception) {
            self::assertSame('oidc_client_id_duplicates', $exception->errorCode);
            self::assertSame(['duplicate-client'], $exception->duplicateClientIds);

            // #2766 independent review: the recovery instruction must name
            // the actual migration runner, never schema:sync (this entity
            // declares no #[StorageUniqueKey], so schema:sync cannot
            // materialize this migration-only index).
            self::assertStringContainsString('bin/waaseyaa migrate', $exception->getMessage());
            self::assertStringNotContainsString('schema:sync', $exception->getMessage());
        }

        // No row was touched: both duplicates and the unrelated row survive intact.
        self::assertSame(3, (int) $connection->fetchOne('SELECT COUNT(*) FROM oidc_client'));
        self::assertSame(
            ['First', 'Second'],
            $connection->fetchFirstColumn(
                "SELECT name FROM oidc_client WHERE client_id = 'duplicate-client' ORDER BY name",
            ),
        );

        // And the index was not created.
        $indexes = $connection->createSchemaManager()->listTableIndexes('oidc_client');
        self::assertArrayNotHasKey('oidc_client_client_id_unique', $indexes);
    }

    #[Test]
    public function blankClientIdRowsAreExcludedFromTheUniquenessScope(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema = new SchemaBuilder($connection);
        $this->applyBaseMigration($schema);

        // Two rows never assigned a client_id (the base migration's column
        // default). These carry no registry identity and must not block
        // materialization of the constraint for real client_id values.
        $connection->insert('oidc_client', ['client_id' => '', 'uuid' => 'u1', 'name' => 'Blank one']);
        $connection->insert('oidc_client', ['client_id' => '', 'uuid' => 'u2', 'name' => 'Blank two']);

        $this->applyTargetMigration($schema);

        $indexes = $connection->createSchemaManager()->listTableIndexes('oidc_client');
        self::assertArrayHasKey('oidc_client_client_id_unique', $indexes);

        // A third blank row is still permitted (outside the partial index's scope).
        $connection->insert('oidc_client', ['client_id' => '', 'uuid' => 'u3', 'name' => 'Blank three']);
        self::assertSame(3, (int) $connection->fetchOne("SELECT COUNT(*) FROM oidc_client WHERE client_id = ''"));
    }

    private function applyBaseMigration(SchemaBuilder $schema): void
    {
        $migration = require dirname(__DIR__, 2) . '/migrations/2026_04_26_000001_oidc_client_schema.php';
        self::assertInstanceOf(Migration::class, $migration);
        $migration->up($schema);
    }

    private function applyTargetMigration(SchemaBuilder $schema): void
    {
        $migration = require dirname(__DIR__, 2) . '/migrations/2026_09_06_000009_oidc_client_id_unique_key.php';
        self::assertInstanceOf(Migration::class, $migration);
        $migration->up($schema);
    }

    private function expectDatabaseUniqueConstraintViolation(): void
    {
        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
    }
}
