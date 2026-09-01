<?php

declare(strict_types=1);

namespace Waaseyaa\Database\Tests\Unit\Schema;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\Schema\DBALSchema;

/**
 * A targeted schema mutation must emit SQL for its own table only (#2804).
 *
 * The mutators introspected the whole schema, cloned it, changed one table and
 * compared schema-to-schema. Doctrine's introspection round-trip is lossy, so
 * tables that were never touched can compare as changed against themselves —
 * a table with a composite primary key and single-column foreign keys reads as
 * needing foreign-key indexes it does not have. `getAlterSchemaSQL()` then
 * rebuilds them through SQLite's copy-and-replace, and the replacement is
 * generated from the degraded introspection rather than the real DDL.
 *
 * What survives that rebuild is only what Doctrine models: a composite primary
 * key returns as `INTEGER PRIMARY KEY AUTOINCREMENT`, and table-level UNIQUE
 * and CHECK constraints and triggers are silently dropped.
 *
 * The reported casualty was `waaseyaa_config_activation_v2`, whose authority-
 * scoped key is what lets two configuration authorities each hold activation
 * sequence 1. Rebuilt with a global autoincrement key the second authority
 * collides; with a single authority the same rebuild degrades the schema and
 * nothing fails, which is the more dangerous shape.
 */
#[CoversClass(DBALSchema::class)]
final class DBALSchemaTargetedMutationTest extends TestCase
{
    private DBALDatabase $db;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();

        $this->connection()->executeStatement('CREATE TABLE generation (authority_id VARCHAR(64) NOT NULL PRIMARY KEY)');
        $this->connection()->executeStatement('CREATE TABLE candidate (request_id VARCHAR(128) NOT NULL PRIMARY KEY)');

        // Composite primary key plus single-column foreign keys: the shape that
        // compares as changed against itself, because Doctrine expects indexes
        // on the foreign-key columns that the declared DDL does not create.
        $this->connection()->executeStatement(<<<'SQL'
            CREATE TABLE activation (
                authority_id VARCHAR(64) NOT NULL,
                activation_sequence INTEGER NOT NULL CHECK (activation_sequence > 0),
                activation_request_id VARCHAR(128) NOT NULL,
                is_genesis INTEGER NOT NULL DEFAULT 0 CHECK (is_genesis IN (0, 1)),
                PRIMARY KEY (authority_id, activation_sequence),
                UNIQUE (authority_id, activation_request_id),
                FOREIGN KEY (authority_id) REFERENCES generation (authority_id),
                FOREIGN KEY (activation_request_id) REFERENCES candidate (request_id)
            )
            SQL);
        $this->connection()->executeStatement(<<<'SQL'
            CREATE TRIGGER activation_genesis_guard
            BEFORE INSERT ON activation
            FOR EACH ROW WHEN NEW.is_genesis = 1 AND NEW.activation_sequence <> 1
            BEGIN
                SELECT RAISE(ABORT, 'Genesis activation must be the first activation.');
            END
            SQL);

        $this->db->schema()->createTable('target', [
            'fields' => ['id' => ['type' => 'serial'], 'label' => ['type' => 'varchar', 'length' => 32]],
            'primary key' => ['id'],
        ]);
    }

    #[Test]
    public function a_targeted_add_field_does_not_rebuild_an_unrelated_table(): void
    {
        $before = $this->tableSql('activation');

        $this->db->schema()->addField('target', 'added', ['type' => 'varchar', 'length' => 16]);

        self::assertSame($before, $this->tableSql('activation'));
        self::assertStringContainsString('PRIMARY KEY (authority_id, activation_sequence)', $this->tableSql('activation'));
        self::assertStringNotContainsString('AUTOINCREMENT', $this->tableSql('activation'));
    }

    #[Test]
    public function two_authorities_still_each_hold_activation_sequence_one(): void
    {
        $this->insertActivation('authority-a', 1, 'request-a');

        $this->db->schema()->addField('target', 'added', ['type' => 'varchar', 'length' => 16]);

        // Only insertable while the primary key remains authority-scoped. A
        // rebuilt single-column autoincrement key makes this collide, which is
        // the reported #2804 failure.
        $this->insertActivation('authority-b', 1, 'request-b');

        self::assertSame(
            2,
            (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM activation WHERE activation_sequence = 1'),
        );
    }

    #[Test]
    public function a_targeted_mutation_preserves_an_unrelated_trigger(): void
    {
        $this->db->schema()->addField('target', 'added', ['type' => 'varchar', 'length' => 16]);

        self::assertSame(
            'activation_genesis_guard',
            $this->connection()->fetchOne(
                "SELECT name FROM sqlite_master WHERE type = 'trigger' AND tbl_name = 'activation'",
            ),
        );
    }

    #[Test]
    public function a_targeted_mutation_preserves_an_unrelated_check_constraint(): void
    {
        $this->db->schema()->addField('target', 'added', ['type' => 'varchar', 'length' => 16]);

        // Single-authority degradation is silent: nothing collides, so the loss
        // only shows when the guard is exercised directly.
        $this->expectExceptionMessageMatches('/CHECK constraint failed/');
        $this->insertActivation('authority-a', 0, 'request-a');
    }

    #[Test]
    public function a_targeted_mutation_preserves_an_unrelated_unique_constraint(): void
    {
        $this->insertActivation('authority-a', 1, 'request-a');

        $this->db->schema()->addField('target', 'added', ['type' => 'varchar', 'length' => 16]);

        $this->expectExceptionMessageMatches('/UNIQUE constraint failed/');
        $this->insertActivation('authority-a', 2, 'request-a');
    }

    #[Test]
    public function sibling_mutators_are_equally_targeted(): void
    {
        $before = $this->tableSql('activation');

        $this->db->schema()->addIndex('target', 'target_label_idx', ['label']);
        $this->db->schema()->dropIndex('target', 'target_label_idx');
        $this->db->schema()->addUniqueKey('target', 'target_label_unique', ['label']);
        $this->db->schema()->addField('target', 'second', ['type' => 'int']);
        $this->db->schema()->dropField('target', 'second');

        self::assertSame($before, $this->tableSql('activation'));
    }

    #[Test]
    public function add_foreign_key_is_targeted(): void
    {
        $before = $this->tableSql('activation');
        $triggers = $this->triggerCount('activation');

        $this->db->schema()->addField('target', 'authority_id', ['type' => 'varchar', 'length' => 64]);
        $this->db->schema()->addForeignKey(
            'target',
            'fk_target_generation',
            ['authority_id'],
            'generation',
            ['authority_id'],
        );

        // addForeignKey rebuilds its own table on SQLite, which is the #2804
        // sibling most likely to reach past it: the constraint it adds names a
        // second table. The unrelated table must still be untouched.
        self::assertSame($before, $this->tableSql('activation'));
        self::assertSame($triggers, $this->triggerCount('activation'));
    }

    #[Test]
    public function add_primary_key_refuses_on_sqlite_without_touching_any_table(): void
    {
        $before = $this->tableSql('activation');
        $targetBefore = $this->tableSql('target');

        // SQLite cannot add a primary key to an existing table, so the mutator
        // refuses. The refusal must be total: no partial whole-schema alter may
        // have run before it, which is what the unrelated table proves.
        try {
            $this->db->schema()->addPrimaryKey('target', ['label']);
            self::fail('addPrimaryKey must refuse on SQLite.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('SQLite does not support adding a primary key', $e->getMessage());
        }

        self::assertSame($before, $this->tableSql('activation'));
        self::assertSame($targetBefore, $this->tableSql('target'));
    }

    private function insertActivation(string $authority, int $sequence, string $request, int $isGenesis = 0): void
    {
        $this->connection()->executeStatement('INSERT OR IGNORE INTO generation (authority_id) VALUES (?)', [$authority]);
        $this->connection()->executeStatement('INSERT OR IGNORE INTO candidate (request_id) VALUES (?)', [$request]);
        $this->connection()->executeStatement(
            'INSERT INTO activation (authority_id, activation_sequence, activation_request_id, is_genesis) VALUES (?, ?, ?, ?)',
            [$authority, $sequence, $request, $isGenesis],
        );
    }

    private function triggerCount(string $table): int
    {
        return (int) $this->connection()->fetchOne(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND tbl_name = ?",
            [$table],
        );
    }

    private function tableSql(string $table): string
    {
        return (string) $this->connection()->fetchOne(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?",
            [$table],
        );
    }

    private function connection(): Connection
    {
        return $this->db->getConnection();
    }
}
