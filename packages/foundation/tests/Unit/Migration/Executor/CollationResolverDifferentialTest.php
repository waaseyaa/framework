<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Migration\Executor;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Migration\Executor\IncompatibleSchemaStateException;
use Waaseyaa\Foundation\Migration\Executor\OpPrecondition;
use Waaseyaa\Foundation\Migration\Executor\OpPreconditionResolver;
use Waaseyaa\Foundation\Schema\Diff\AddIndex;

/**
 * The resolver's behaviour on the column shapes that previously mis-parsed.
 *
 * The parser being right is necessary but not sufficient: what matters is that
 * the resolver **refuses** a same-named index whose collation differs from the
 * one the authored index would inherit, and **accepts** one that matches. The
 * concrete harm of the earlier defect was that a `BINARY` unique index was
 * accepted for a `NOCASE` column, permitting both `a` and `A` where the authored
 * contract distinguishes them — so each case here asserts that behaviour
 * directly against a live database.
 *
 * @see docs/change-records/FW-2701.md
 */
#[CoversClass(OpPreconditionResolver::class)]
final class CollationResolverDifferentialTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DBALDatabase::createSqlite()->getConnection();
    }

    #[Test]
    #[DataProvider('nocaseColumnDefinitions')]
    public function a_binary_index_is_refused_for_a_nocase_column(string $columnDefinition): void
    {
        $this->createTable($columnDefinition);
        // An index explicitly under BINARY, i.e. NOT what the authored index
        // would inherit.
        $this->connection->executeStatement('CREATE UNIQUE INDEX idx_name ON t ("name" COLLATE BINARY)');

        $this->expectException(IncompatibleSchemaStateException::class);
        $this->expectExceptionMessageMatches('/would index under NOCASE.*found BINARY/');

        new OpPreconditionResolver($this->connection)
            ->resolve(new AddIndex('t', ['name'], 'idx_name', true));
    }

    #[Test]
    #[DataProvider('nocaseColumnDefinitions')]
    public function an_inherited_index_is_accepted_for_the_same_column(string $columnDefinition): void
    {
        $this->createTable($columnDefinition);
        // No COLLATE clause: the index inherits the column's collation, which is
        // exactly what the compiler emits.
        $this->connection->executeStatement('CREATE UNIQUE INDEX idx_name ON t ("name")');

        self::assertSame(
            OpPrecondition::AlreadySatisfied,
            new OpPreconditionResolver($this->connection)
                ->resolve(new AddIndex('t', ['name'], 'idx_name', true)),
        );
    }

    #[Test]
    #[DataProvider('nocaseColumnDefinitions')]
    public function the_refused_binary_index_really_does_permit_case_variants(string $columnDefinition): void
    {
        // The harm, demonstrated rather than asserted: under BINARY the unique
        // index admits both spellings; under the authored NOCASE contract it
        // must not. This is why accepting the BINARY index was a defect.
        $this->createTable($columnDefinition);
        $this->connection->executeStatement('CREATE UNIQUE INDEX idx_name ON t ("name" COLLATE BINARY)');

        $this->connection->executeStatement('INSERT INTO t (name) VALUES (?)', ['a']);
        $this->connection->executeStatement('INSERT INTO t (name) VALUES (?)', ['A']);

        self::assertSame(
            2,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM t'),
            'BINARY uniqueness admits both, which NOCASE uniqueness would reject',
        );
    }

    /** @return array<string, array{string}> */
    public static function nocaseColumnDefinitions(): array
    {
        return [
            'comment between COLLATE and argument' => ['name TEXT COLLATE/* c */NOCASE'],
            'repeated clause, later wins' => ['name TEXT COLLATE BINARY COLLATE NOCASE'],
            'COLLATE inside the type identifier' => ['name acollate BINARY COLLATE NOCASE'],
            'line comment between COLLATE and argument' => ["name TEXT COLLATE -- c\n NOCASE"],
            'quoted collation name' => ['name TEXT COLLATE "NOCASE"'],
            // A UTF-8 BOM at a token start is whitespace to SQLite's lexer, so
            // the clause is real and the column collates NOCASE.
            'byte-order mark before COLLATE' => ["name TEXT \xEF\xBB\xBFCOLLATE NOCASE"],
        ];
    }

    #[Test]
    public function an_unreadable_collation_clause_fails_closed_rather_than_assuming_binary(): void
    {
        $this->connection->executeStatement('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
        $this->connection->executeStatement('CREATE UNIQUE INDEX idx_name ON t ("name")');
        // Replace the stored schema with a shape the parser cannot resolve.
        $this->connection->executeStatement('PRAGMA writable_schema = ON');
        $this->connection->executeStatement(
            "UPDATE sqlite_master SET sql = 'CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT COLLATE)' WHERE name = 't'",
        );
        $this->connection->executeStatement('PRAGMA writable_schema = OFF');

        $this->expectException(IncompatibleSchemaStateException::class);
        $this->expectExceptionMessageMatches('/could not be established/');

        new OpPreconditionResolver($this->connection)
            ->resolve(new AddIndex('t', ['name'], 'idx_name', true));
    }

    private function createTable(string $columnDefinition): void
    {
        $this->connection->executeStatement(
            sprintf('CREATE TABLE t (id INTEGER PRIMARY KEY, %s)', $columnDefinition),
        );
    }
}
