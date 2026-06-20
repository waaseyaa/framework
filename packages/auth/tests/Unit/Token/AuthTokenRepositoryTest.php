<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit\Token;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Auth\Token\AuthTokenRepository;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\SchemaInterface;

/**
 * Guards the idempotent, race-safe schema bootstrap. `ensureSchema()` runs on the
 * request hot path (route registration), and under FrankenPHP classic
 * `php-server` many cold boots can hit it concurrently across worker threads.
 */
#[CoversClass(AuthTokenRepository::class)]
final class AuthTokenRepositoryTest extends TestCase
{
    #[Test]
    public function ensure_schema_is_idempotent_and_leaves_a_usable_table(): void
    {
        $repo = new AuthTokenRepository(DBALDatabase::createSqlite(), 'secret');

        // A second boot against the same DB must not 500 (the acceptance gate).
        $repo->ensureSchema();
        $repo->ensureSchema();

        // ...and the table is actually usable end to end.
        $token = $repo->createToken(7, 'reset', 3600);
        $validated = $repo->validateToken($token, 'reset');

        self::assertIsArray($validated);
        self::assertSame('7', $validated['user_id']);
    }

    #[Test]
    public function ensure_schema_tolerates_a_table_created_by_a_concurrent_boot(): void
    {
        // Race loser: tableExists() is false when we check (the winner hasn't
        // committed yet), createTable() then throws "already exists", and a
        // re-check shows the table now present. ensureSchema() must swallow it.
        $db = $this->dbWithSchema(new class implements SchemaInterface {
            private int $tableExistsCalls = 0;

            public function tableExists(string $table): bool
            {
                // false on the guard check, true on the post-failure re-check.
                return $this->tableExistsCalls++ > 0;
            }

            public function createTable(string $name, array $spec): void
            {
                throw new \RuntimeException('table auth_tokens already exists');
            }

            public function fieldExists(string $table, string $field): bool { throw new \LogicException('unused'); }
            public function dropTable(string $table): void { throw new \LogicException('unused'); }
            public function addField(string $table, string $field, array $spec): void { throw new \LogicException('unused'); }
            public function dropField(string $table, string $field): void { throw new \LogicException('unused'); }
            public function addIndex(string $table, string $name, array $fields): void { throw new \LogicException('unused'); }
            public function dropIndex(string $table, string $name): void { throw new \LogicException('unused'); }
            public function addUniqueKey(string $table, string $name, array $fields): void { throw new \LogicException('unused'); }
            public function addPrimaryKey(string $table, array $fields): void { throw new \LogicException('unused'); }
            public function listTableNames(): array { throw new \LogicException('unused'); }
        });

        new AuthTokenRepository($db, 'secret')->ensureSchema();

        // No exception = the concurrent "already exists" was tolerated.
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function ensure_schema_rethrows_a_genuine_create_failure(): void
    {
        // createTable fails and the table is still absent on re-check → a real
        // error, not a benign race: it must propagate.
        $db = $this->dbWithSchema(new class implements SchemaInterface {
            public function tableExists(string $table): bool { return false; }

            public function createTable(string $name, array $spec): void
            {
                throw new \RuntimeException('disk I/O error');
            }

            public function fieldExists(string $table, string $field): bool { throw new \LogicException('unused'); }
            public function dropTable(string $table): void { throw new \LogicException('unused'); }
            public function addField(string $table, string $field, array $spec): void { throw new \LogicException('unused'); }
            public function dropField(string $table, string $field): void { throw new \LogicException('unused'); }
            public function addIndex(string $table, string $name, array $fields): void { throw new \LogicException('unused'); }
            public function dropIndex(string $table, string $name): void { throw new \LogicException('unused'); }
            public function addUniqueKey(string $table, string $name, array $fields): void { throw new \LogicException('unused'); }
            public function addPrimaryKey(string $table, array $fields): void { throw new \LogicException('unused'); }
            public function listTableNames(): array { throw new \LogicException('unused'); }
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('disk I/O error');

        new AuthTokenRepository($db, 'secret')->ensureSchema();
    }

    private function dbWithSchema(SchemaInterface $schema): DatabaseInterface
    {
        return new class ($schema) implements DatabaseInterface {
            public function __construct(private readonly SchemaInterface $schema) {}

            public function schema(): SchemaInterface
            {
                return $this->schema;
            }

            public function select(string $table, string $alias = ''): \Waaseyaa\Database\SelectInterface { throw new \LogicException('unused'); }
            public function insert(string $table): \Waaseyaa\Database\InsertInterface { throw new \LogicException('unused'); }
            public function update(string $table): \Waaseyaa\Database\UpdateInterface { throw new \LogicException('unused'); }
            public function delete(string $table): \Waaseyaa\Database\DeleteInterface { throw new \LogicException('unused'); }
            public function transaction(string $name = ''): \Waaseyaa\Database\TransactionInterface { throw new \LogicException('unused'); }
            public function query(string $sql, array $args = []): \Traversable { throw new \LogicException('unused'); }
            public function quoteIdentifier(string $identifier): string { throw new \LogicException('unused'); }
        };
    }
}
