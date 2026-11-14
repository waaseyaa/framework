<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement\Tests\Unit\Migration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

#[CoversNothing]
final class EngagementAuxiliarySchemaMigrationTest extends TestCase
{
    #[Test]
    public function creates_exact_tables_idempotently_and_drops_them_on_rollback(): void
    {
        $connection = DBALDatabase::createSqlite()->getConnection();
        $migration = require dirname(__DIR__, 3) . '/migrations/2026_11_13_000001_engagement_auxiliary_schema.php';
        $schema = new SchemaBuilder($connection);

        $migration->up($schema);
        $migration->up($schema);

        $expected = [
            'engagement_comment_index',
            'engagement_like_identity',
            'engagement_actor_erasure',
            'engagement_root_erasure',
            'engagement_policy_state',
            'engagement_rate_limits',
            'engagement_receipts',
            'engagement_upgrade_progress',
        ];
        foreach ($expected as $table) {
            self::assertTrue($schema->hasTable($table), $table);
        }
        $progressColumns = array_map(
            static fn ($column): string => $column->getName(),
            $connection->createSchemaManager()->listTableColumns('engagement_upgrade_progress'),
        );
        sort($progressColumns);
        self::assertSame(['complete', 'id', 'last_id'], $progressColumns);
        self::assertSame(
            ['requested_at', 'root_type', 'root_uuid'],
            $this->columnNames($connection, 'engagement_root_erasure'),
        );
        self::assertSame(
            ['actor_id', 'requested_at'],
            $this->columnNames($connection, 'engagement_actor_erasure'),
        );
        self::assertSame(['root_type', 'root_uuid'], $this->primaryColumns($connection, 'engagement_root_erasure'));
        self::assertSame(['actor_id'], $this->primaryColumns($connection, 'engagement_actor_erasure'));
        self::assertSame(1, (int) $connection->fetchOne('SELECT visibility_generation FROM engagement_policy_state WHERE id = 1'));

        $migration->down($schema);
        foreach ($expected as $table) {
            self::assertFalse($schema->hasTable($table), $table);
        }
    }

    /** @return list<string> */
    private function columnNames(\Doctrine\DBAL\Connection $connection, string $table): array
    {
        $columns = array_map(
            static fn ($column): string => $column->getName(),
            $connection->createSchemaManager()->listTableColumns($table),
        );
        sort($columns);
        return $columns;
    }

    /** @return list<string> */
    private function primaryColumns(\Doctrine\DBAL\Connection $connection, string $table): array
    {
        foreach ($connection->createSchemaManager()->listTableIndexes($table) as $index) {
            if ($index->isPrimary()) {
                return $index->getColumns();
            }
        }
        self::fail(sprintf('Table %s has no primary key.', $table));
    }
}
