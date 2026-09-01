<?php

declare(strict_types=1);

namespace Waaseyaa\Database\Tests\Unit\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\Schema\DBALSchema;

/**
 * #2761: the production/staging fail-closed foreign-key readiness check
 * ({@see \Waaseyaa\EntityStorage\SqlSchemaHandler} `assertDeclaredForeignKeysReady()`,
 * backed by {@see DBALSchema::foreignKeyExists()}) must be pure read-only
 * introspection on every supported database platform — never DDL. No live
 * MySQL/PostgreSQL server is available in this environment, so platform
 * neutrality is proven the same way `DBALSchemaTest::testAddPrimaryKeyUsesPortableSchemaDiffOutsideSqlite`
 * already proves portable DDL generation: a mocked DBAL `Connection` reports
 * the platform class under test while `executeStatement()` is asserted
 * never called. SQLite is additionally proven against a real in-memory
 * database. Deferred: real fresh/populated-upgrade/concurrent-recovery
 * behavior against live MySQL/MariaDB/PostgreSQL server processes — no such
 * server is available in this environment.
 *
 * @see DBALSchemaTest
 */
#[CoversClass(DBALSchema::class)]
final class DBALSchemaForeignKeyPlatformTest extends TestCase
{
    #[Test]
    public function foreignKeyExistsIsReadOnlyOnRealSqlite(): void
    {
        $db = DBALDatabase::createSqlite();
        $db->schema()->createTable('fk_ref', [
            'fields' => ['id' => ['type' => 'serial']],
            'primary key' => ['id'],
        ]);
        $db->schema()->createTable('fk_owner', [
            'fields' => ['id' => ['type' => 'serial']],
            'primary key' => ['id'],
        ]);

        self::assertFalse($db->schema()->foreignKeyExists('fk_owner', 'fk_owner_fk_ref_fk'));

        $db->schema()->addForeignKey('fk_owner', 'fk_owner_fk_ref_fk', ['id'], 'fk_ref', ['id']);

        self::assertTrue($db->schema()->foreignKeyExists('fk_owner', 'fk_owner_fk_ref_fk'));
    }

    /**
     * @return iterable<string, array{0: AbstractPlatform}>
     */
    public static function platforms(): iterable
    {
        yield 'MySQL' => [new MySQL80Platform()];
        yield 'PostgreSQL' => [new PostgreSQLPlatform()];
        yield 'SQLite' => [new SQLitePlatform()];
    }

    #[Test]
    #[DataProvider('platforms')]
    public function foreignKeyExistsNeverIssuesDdlAndReportsAbsence(AbstractPlatform $platform): void
    {
        $manager = $this->createMock(AbstractSchemaManager::class);
        $manager->expects(self::once())
            ->method('listTableForeignKeys')
            ->with('taxonomy_term')
            ->willReturn([]);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($manager);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->expects(self::never())->method('executeStatement');

        $schema = new DBALSchema($connection);

        self::assertFalse($schema->foreignKeyExists('taxonomy_term', 'taxonomy_term_vocabulary_fk'));
    }

    #[Test]
    #[DataProvider('platforms')]
    public function foreignKeyExistsNeverIssuesDdlAndReportsPresence(AbstractPlatform $platform): void
    {
        $manager = $this->createMock(AbstractSchemaManager::class);
        $manager->expects(self::once())
            ->method('listTableForeignKeys')
            ->with('taxonomy_term')
            ->willReturn([
                new ForeignKeyConstraint(['vid'], 'taxonomy_vocabulary', ['vid'], 'taxonomy_term_vocabulary_fk'),
            ]);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($manager);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->expects(self::never())->method('executeStatement');

        $schema = new DBALSchema($connection);

        self::assertTrue($schema->foreignKeyExists('taxonomy_term', 'taxonomy_term_vocabulary_fk'));
    }

    #[Test]
    #[DataProvider('platforms')]
    public function tableExistsNeverIssuesDdl(AbstractPlatform $platform): void
    {
        $manager = $this->createMock(AbstractSchemaManager::class);
        $manager->expects(self::once())
            ->method('tablesExist')
            ->with(['taxonomy_vocabulary'])
            ->willReturn(true);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($manager);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->expects(self::never())->method('executeStatement');

        $schema = new DBALSchema($connection);

        self::assertTrue($schema->tableExists('taxonomy_vocabulary'));
    }
}
