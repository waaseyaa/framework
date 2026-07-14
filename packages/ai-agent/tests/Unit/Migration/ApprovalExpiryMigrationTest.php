<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit\Migration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

final class ApprovalExpiryMigrationTest extends TestCase
{
    #[Test]
    public function upgradeAddsNullableApprovalExpiryToAnExistingRunTable(): void
    {
        $database = DBALDatabase::createSqlite();
        $database->getConnection()->executeStatement(
            'CREATE TABLE agent_run (id VARCHAR(36) NOT NULL PRIMARY KEY)',
        );
        $migration = require \dirname(__DIR__, 3)
            . '/migrations/2026_07_14_000001_add_approval_expires_at.php';
        self::assertInstanceOf(Migration::class, $migration);

        $schema = new SchemaBuilder($database->getConnection());
        $migration->up($schema);
        $migration->up($schema);

        self::assertTrue($schema->hasColumn('agent_run', 'approval_expires_at'));
    }
}
