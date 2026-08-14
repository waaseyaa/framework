<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Migration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Publishing\Idempotency\IdempotencyStore;
use Waaseyaa\Relationship\RelationshipSchemaManager;
use Waaseyaa\State\SqlState;

/** Retained-red proof for small migration-less infrastructure stores. */
final class InfrastructureRuntimeSchemaAuthorityTest extends TestCase
{
    #[Test]
    public function broadcast_construction_refuses_missing_schema_without_installing_tables(): void
    {
        $database = DBALDatabase::createSqlite();

        $this->assertRefused(static fn(): mixed => new BroadcastStorage($database));

        self::assertFalse($database->schema()->tableExists('_broadcast_log'));
        self::assertFalse($database->schema()->tableExists('_broadcast_retained'));
    }

    #[Test]
    public function publishing_operation_refuses_missing_schema_without_installing_table(): void
    {
        $database = DBALDatabase::createSqlite();
        $store = new IdempotencyStore($database);

        $this->assertRefused(static fn(): mixed => $store->execute('key', 'publish', [], static fn(): array => ['ok' => true]));

        self::assertFalse($database->schema()->tableExists('publishing_idempotency'));
    }

    #[Test]
    public function state_read_refuses_missing_schema_without_installing_table(): void
    {
        $database = DBALDatabase::createSqlite();
        $state = new SqlState($database, str_repeat('s', 32));

        $this->assertRefused(static fn(): mixed => $state->get('missing'));

        self::assertFalse($database->schema()->tableExists('state'));
    }

    #[Test]
    public function relationship_runtime_sync_refuses_incomplete_schema_without_repair(): void
    {
        $database = DBALDatabase::createSqlite();
        $database->getConnection()->executeStatement(
            'CREATE TABLE relationship (id INTEGER PRIMARY KEY, relationship_type TEXT NOT NULL)',
        );

        $this->assertRefused(static fn(): mixed => new RelationshipSchemaManager($database)->ensure());

        self::assertFalse($database->schema()->fieldExists('relationship', 'from_entity_type'));
    }

    /** @param \Closure(): mixed $operation */
    private function assertRefused(\Closure $operation): void
    {
        try {
            $operation();
            self::fail('Runtime schema mutation was accepted.');
        } catch (\Throwable $exception) {
            $messages = [];
            do {
                $messages[] = $exception->getMessage();
                $exception = $exception->getPrevious();
            } while ($exception !== null);

            self::assertStringContainsString('S1-DB106', implode("\n", $messages));
        }
    }
}
