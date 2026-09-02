<?php

declare(strict_types=1);

namespace Waaseyaa\Deployer\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Deployer\RuntimeState\SqliteSchemaSignature;

/**
 * The structural signature accepts every spelling of one schema and rejects
 * every change of meaning, including the semantics only DDL text carries.
 */
#[CoversClass(SqliteSchemaSignature::class)]
final class SqliteSchemaSignatureTest extends TestCase
{
    /** @return iterable<string, array{0:list<string>,1:list<string>}> */
    public static function equivalentSpellings(): iterable
    {
        yield 'line breaks and indentation' => [
            ['CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT,channel TEXT NOT NULL,data TEXT NOT NULL DEFAULT \'{}\',created_at REAL NOT NULL)'],
            ["CREATE TABLE t (\n                    id INTEGER PRIMARY KEY AUTOINCREMENT,\n                    channel TEXT NOT NULL,\n                    data TEXT NOT NULL DEFAULT '{}',\n                    created_at REAL NOT NULL\n                )"],
        ];
        yield 'identifier quoting and keyword case' => [
            ['CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT NOT NULL)'],
            ['create table "t" ("id" integer primary key, `name` text not null)'],
        ];
        yield 'CLOB versus TEXT and explicit DEFAULT NULL' => [
            ['CREATE TABLE t (id TEXT PRIMARY KEY NOT NULL, user_id CLOB DEFAULT NULL, consumed_at INTEGER DEFAULT NULL, meta CLOB DEFAULT NULL)'],
            ['CREATE TABLE t (id TEXT PRIMARY KEY NOT NULL, user_id TEXT, consumed_at INTEGER, meta TEXT)'],
        ];
        yield 'VARCHAR(255) is TEXT affinity outside STRICT' => [
            ['CREATE TABLE t (id INTEGER PRIMARY KEY, name VARCHAR(255) NOT NULL)'],
            ['CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT NOT NULL)'],
        ];
        yield 'inline versus table-level single-column primary key' => [
            ['CREATE TABLE t (bucket_key TEXT PRIMARY KEY NOT NULL, hits INTEGER NOT NULL)'],
            ['CREATE TABLE t (bucket_key TEXT NOT NULL, hits INTEGER NOT NULL, PRIMARY KEY (bucket_key))'],
        ];
        yield 'inline versus table-level unique' => [
            ['CREATE TABLE t (id INTEGER PRIMARY KEY, code TEXT UNIQUE)'],
            ['CREATE TABLE t (id INTEGER PRIMARY KEY, code TEXT, UNIQUE (code))'],
        ];
        yield 'constraint names and comments' => [
            ['CREATE TABLE t (id INTEGER PRIMARY KEY, n INTEGER CONSTRAINT positive CHECK (n > 0))'],
            ["CREATE TABLE t (id INTEGER PRIMARY KEY, n INTEGER CHECK (n > 0) -- trailing\n)"],
        ];
        yield 'parenthesised literal default' => [
            ["CREATE TABLE t (id INTEGER PRIMARY KEY, data TEXT DEFAULT '{}')"],
            ["CREATE TABLE t (id INTEGER PRIMARY KEY, data TEXT DEFAULT ('{}'))"],
        ];
        yield 'unique constraints declared in a different order' => [
            ['CREATE TABLE t (id INTEGER PRIMARY KEY, a TEXT, b TEXT, UNIQUE (a), UNIQUE (b))'],
            ['CREATE TABLE t (id INTEGER PRIMARY KEY, a TEXT, b TEXT, UNIQUE (b), UNIQUE (a))'],
        ];
        yield 'index spelling' => [
            ['CREATE TABLE t (id INTEGER PRIMARY KEY, user_id INTEGER, revoked INTEGER)', 'CREATE INDEX active ON t (user_id) WHERE revoked = 0'],
            ['CREATE TABLE t (id INTEGER PRIMARY KEY, user_id INTEGER, revoked INTEGER)', "CREATE INDEX \"active\" ON \"t\" (\n  \"user_id\"\n) WHERE revoked=0"],
        ];
        yield 'foreign key spelling and constraint name' => [
            ['CREATE TABLE p (id INTEGER PRIMARY KEY)', 'CREATE TABLE t (id INTEGER PRIMARY KEY, p_id INTEGER CONSTRAINT fk_p REFERENCES p (id) ON DELETE CASCADE)'],
            ['CREATE TABLE p (id INTEGER PRIMARY KEY)', 'CREATE TABLE t (id INTEGER PRIMARY KEY, p_id INTEGER, FOREIGN KEY (p_id) REFERENCES "p"("id") ON DELETE CASCADE)'],
        ];
        yield 'bare DEFERRABLE is DEFERRABLE INITIALLY IMMEDIATE' => [
            ['CREATE TABLE p (id INTEGER PRIMARY KEY)', 'CREATE TABLE t (id INTEGER PRIMARY KEY, r INTEGER REFERENCES p (id) DEFERRABLE)'],
            ['CREATE TABLE p (id INTEGER PRIMARY KEY)', 'CREATE TABLE t (id INTEGER PRIMARY KEY, r INTEGER REFERENCES p (id) DEFERRABLE INITIALLY IMMEDIATE)'],
        ];
        yield 'NOT DEFERRABLE INITIALLY DEFERRED is the default' => [
            ['CREATE TABLE p (id INTEGER PRIMARY KEY)', 'CREATE TABLE t (id INTEGER PRIMARY KEY, r INTEGER REFERENCES p (id) NOT DEFERRABLE INITIALLY DEFERRED)'],
            ['CREATE TABLE p (id INTEGER PRIMARY KEY)', 'CREATE TABLE t (id INTEGER PRIMARY KEY, r INTEGER REFERENCES p (id))'],
        ];
        yield 'duplicate foreign keys declared in a different order' => [
            ['CREATE TABLE p (id INTEGER PRIMARY KEY)', 'CREATE TABLE t (id INTEGER PRIMARY KEY, r INTEGER, FOREIGN KEY (r) REFERENCES p (id) DEFERRABLE INITIALLY DEFERRED, FOREIGN KEY (r) REFERENCES p (id))'],
            ['CREATE TABLE p (id INTEGER PRIMARY KEY)', 'CREATE TABLE t (id INTEGER PRIMARY KEY, r INTEGER, FOREIGN KEY (r) REFERENCES p (id), FOREIGN KEY (r) REFERENCES p (id) DEFERRABLE INITIALLY DEFERRED)'],
        ];
        yield 'quoted identifiers in every identifier position' => [
            ['CREATE TABLE p (id INTEGER PRIMARY KEY)', 'CREATE TABLE t (id INTEGER PRIMARY KEY, s TEXT COLLATE NOCASE, r INTEGER, UNIQUE (s), FOREIGN KEY (r) REFERENCES p (id))', 'CREATE INDEX s_idx ON t (s COLLATE NOCASE DESC)'],
            ['CREATE TABLE "p" ("id" INTEGER PRIMARY KEY)', 'CREATE TABLE "t" ("id" INTEGER PRIMARY KEY, "s" TEXT COLLATE "nocase", "r" INTEGER, UNIQUE ("s"), FOREIGN KEY ("r") REFERENCES "p" ("id"))', 'CREATE INDEX "s_idx" ON "t" ("s" COLLATE "NOCASE" DESC)'],
        ];
        yield 'foreign keys with distinct actions declared in a different order' => [
            ['CREATE TABLE p (id INTEGER PRIMARY KEY)', 'CREATE TABLE t (id INTEGER PRIMARY KEY, r INTEGER, FOREIGN KEY (r) REFERENCES p (id) ON DELETE CASCADE DEFERRABLE INITIALLY DEFERRED, FOREIGN KEY (r) REFERENCES p (id) ON DELETE RESTRICT)'],
            ['CREATE TABLE p (id INTEGER PRIMARY KEY)', 'CREATE TABLE t (id INTEGER PRIMARY KEY, r INTEGER, FOREIGN KEY (r) REFERENCES p (id) ON DELETE RESTRICT, FOREIGN KEY (r) REFERENCES p (id) ON DELETE CASCADE DEFERRABLE INITIALLY DEFERRED)'],
        ];
        yield 'explicit default actions and MATCH, which SQLite ignores' => [
            ['CREATE TABLE p (id INTEGER PRIMARY KEY)', 'CREATE TABLE t (id INTEGER PRIMARY KEY, r INTEGER REFERENCES p (id))'],
            ['CREATE TABLE p (id INTEGER PRIMARY KEY)', 'CREATE TABLE t (id INTEGER PRIMARY KEY, r INTEGER REFERENCES p (id) ON UPDATE NO ACTION ON DELETE NO ACTION MATCH FULL)'],
        ];
        yield 'trigger spelling' => [
            ['CREATE TABLE t (id INTEGER PRIMARY KEY, n INTEGER)', 'CREATE TRIGGER guard BEFORE INSERT ON t BEGIN SELECT RAISE(ABORT, \'no\') WHERE NEW.n < 0; END'],
            ['CREATE TABLE t (id INTEGER PRIMARY KEY, n INTEGER)', "CREATE TRIGGER guard\n  BEFORE INSERT ON \"t\"\nBEGIN\n  SELECT RAISE(ABORT, 'no') WHERE new.n < 0;\nEND"],
        ];
    }

    #[Test]
    #[DataProvider('equivalentSpellings')]
    public function equivalent_spellings_describe_the_same_schema(array $left, array $right): void
    {
        $a = self::describe($left);
        $b = self::describe($right);

        self::assertNull(SqliteSchemaSignature::firstDifference($a, $b), json_encode([$a, $b], JSON_PRETTY_PRINT));
        self::assertSame($a, $b);
    }

    /** @return iterable<string, array{0:list<string>,1:list<string>,2:string}> */
    public static function semanticChanges(): iterable
    {
        $base = 'CREATE TABLE t (id INTEGER PRIMARY KEY, n INTEGER NOT NULL, s TEXT)';
        yield 'added column' => [[$base], ['CREATE TABLE t (id INTEGER PRIMARY KEY, n INTEGER NOT NULL, s TEXT, extra TEXT)'], 'columns.3'];
        yield 'renamed column' => [[$base], ['CREATE TABLE t (id INTEGER PRIMARY KEY, n INTEGER NOT NULL, other TEXT)'], 'columns.2.name'];
        yield 'reordered columns' => [[$base], ['CREATE TABLE t (id INTEGER PRIMARY KEY, s TEXT, n INTEGER NOT NULL)'], 'columns.1.name'];
        yield 'NOT NULL dropped' => [[$base], ['CREATE TABLE t (id INTEGER PRIMARY KEY, n INTEGER, s TEXT)'], 'columns.1.not_null'];
        yield 'affinity changed' => [[$base], ['CREATE TABLE t (id INTEGER PRIMARY KEY, n TEXT NOT NULL, s TEXT)'], 'columns.1.type'];
        yield 'default value changed' => [['CREATE TABLE t (id INTEGER PRIMARY KEY, s TEXT DEFAULT \'a\')'], ['CREATE TABLE t (id INTEGER PRIMARY KEY, s TEXT DEFAULT \'b\')'], 'columns.1.default'];
        yield 'default expression versus literal' => [['CREATE TABLE t (id INTEGER PRIMARY KEY, s TEXT DEFAULT \'x\')'], ['CREATE TABLE t (id INTEGER PRIMARY KEY, s TEXT DEFAULT (lower(\'X\')))'], 'columns.1.default'];
        yield 'INT primary key is not a rowid alias' => [['CREATE TABLE t (id INTEGER PRIMARY KEY, s TEXT)'], ['CREATE TABLE t (id INT PRIMARY KEY, s TEXT)'], 'rowid_alias'];
        yield 'AUTOINCREMENT added' => [['CREATE TABLE t (id INTEGER PRIMARY KEY, s TEXT)'], ['CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, s TEXT)'], 'autoincrement'];
        yield 'WITHOUT ROWID' => [['CREATE TABLE t (k TEXT PRIMARY KEY NOT NULL, s TEXT)'], ['CREATE TABLE t (k TEXT PRIMARY KEY NOT NULL, s TEXT) WITHOUT ROWID'], 'without_rowid'];
        yield 'STRICT' => [['CREATE TABLE t (id INTEGER PRIMARY KEY, s TEXT)'], ['CREATE TABLE t (id INTEGER PRIMARY KEY, s TEXT) STRICT'], 'strict'];
        yield 'STRICT declared type matters' => [['CREATE TABLE t (id INTEGER PRIMARY KEY, v INT) STRICT'], ['CREATE TABLE t (id INTEGER PRIMARY KEY, v INTEGER) STRICT'], 'columns.1.type'];
        yield 'CHECK expression changed' => [['CREATE TABLE t (id INTEGER PRIMARY KEY, n INTEGER CHECK (n > 0))'], ['CREATE TABLE t (id INTEGER PRIMARY KEY, n INTEGER CHECK (n >= 0))'], 'checks.0'];
        yield 'CHECK removed' => [['CREATE TABLE t (id INTEGER PRIMARY KEY, n INTEGER CHECK (n > 0))'], ['CREATE TABLE t (id INTEGER PRIMARY KEY, n INTEGER)'], 'checks.0'];
        yield 'CHECK with CAST is not a generated column' => [['CREATE TABLE t (id INTEGER PRIMARY KEY, n TEXT CHECK (CAST(n AS INTEGER) > 0))'], ['CREATE TABLE t (id INTEGER PRIMARY KEY, n TEXT CHECK (CAST(n AS INTEGER) > 1))'], 'checks.0'];
        yield 'generated column expression' => [['CREATE TABLE t (id INTEGER PRIMARY KEY, a INTEGER, b INTEGER GENERATED ALWAYS AS (a * 2) VIRTUAL)'], ['CREATE TABLE t (id INTEGER PRIMARY KEY, a INTEGER, b INTEGER GENERATED ALWAYS AS (a * 3) VIRTUAL)'], 'columns.2.generated.expression'];
        yield 'generated column storage' => [['CREATE TABLE t (id INTEGER PRIMARY KEY, a INTEGER, b INTEGER GENERATED ALWAYS AS (a * 2) VIRTUAL)'], ['CREATE TABLE t (id INTEGER PRIMARY KEY, a INTEGER, b INTEGER GENERATED ALWAYS AS (a * 2) STORED)'], 'columns.2.hidden'];
        yield 'column collation' => [['CREATE TABLE t (id INTEGER PRIMARY KEY, s TEXT COLLATE NOCASE)'], ['CREATE TABLE t (id INTEGER PRIMARY KEY, s TEXT COLLATE BINARY)'], 'columns.1.collate'];
        yield 'NOT NULL conflict clause' => [['CREATE TABLE t (id INTEGER PRIMARY KEY, s TEXT NOT NULL ON CONFLICT IGNORE)'], ['CREATE TABLE t (id INTEGER PRIMARY KEY, s TEXT NOT NULL)'], 'columns.1.not_null_conflict'];
        yield 'unique conflict clause' => [['CREATE TABLE t (id INTEGER PRIMARY KEY, s TEXT UNIQUE ON CONFLICT REPLACE)'], ['CREATE TABLE t (id INTEGER PRIMARY KEY, s TEXT UNIQUE)'], 'unique_constraints.0.conflict'];
        yield 'primary key conflict clause' => [['CREATE TABLE t (k TEXT PRIMARY KEY ON CONFLICT REPLACE)'], ['CREATE TABLE t (k TEXT PRIMARY KEY)'], 'primary_key_conflict'];
        yield 'index added' => [[$base], [$base, 'CREATE INDEX n_idx ON t (n)'], 'indexes.0'];
        yield 'index unique flag' => [[$base, 'CREATE INDEX n_idx ON t (n)'], [$base, 'CREATE UNIQUE INDEX n_idx ON t (n)'], 'indexes.0.unique'];
        yield 'index column order' => [[$base, 'CREATE INDEX n_idx ON t (n, s)'], [$base, 'CREATE INDEX n_idx ON t (s, n)'], 'indexes.0.columns.0.name'];
        yield 'index direction' => [[$base, 'CREATE INDEX n_idx ON t (n)'], [$base, 'CREATE INDEX n_idx ON t (n DESC)'], 'indexes.0.columns.0.desc'];
        yield 'index collation' => [[$base, 'CREATE INDEX s_idx ON t (s)'], [$base, 'CREATE INDEX s_idx ON t (s COLLATE NOCASE)'], 'indexes.0.columns.0.collate'];
        yield 'partial index predicate' => [[$base, 'CREATE INDEX n_idx ON t (n) WHERE n > 0'], [$base, 'CREATE INDEX n_idx ON t (n) WHERE n > 1'], 'indexes.0.where'];
        yield 'partial index predicate removed' => [[$base, 'CREATE INDEX n_idx ON t (n) WHERE n > 0'], [$base, 'CREATE INDEX n_idx ON t (n)'], 'indexes.0.partial'];
        yield 'expression index' => [[$base, 'CREATE INDEX s_idx ON t (lower(s))'], [$base, 'CREATE INDEX s_idx ON t (upper(s))'], 'indexes.0.expressions.0'];
        yield 'foreign key target' => [['CREATE TABLE p (id INTEGER PRIMARY KEY)', 'CREATE TABLE q (id INTEGER PRIMARY KEY)', 'CREATE TABLE t (id INTEGER PRIMARY KEY, r INTEGER REFERENCES p (id))'], ['CREATE TABLE p (id INTEGER PRIMARY KEY)', 'CREATE TABLE q (id INTEGER PRIMARY KEY)', 'CREATE TABLE t (id INTEGER PRIMARY KEY, r INTEGER REFERENCES q (id))'], 'foreign_keys.0.table'];
        yield 'foreign key action' => [['CREATE TABLE p (id INTEGER PRIMARY KEY)', 'CREATE TABLE t (id INTEGER PRIMARY KEY, r INTEGER REFERENCES p (id) ON DELETE CASCADE)'], ['CREATE TABLE p (id INTEGER PRIMARY KEY)', 'CREATE TABLE t (id INTEGER PRIMARY KEY, r INTEGER REFERENCES p (id) ON DELETE SET NULL)'], 'foreign_keys.0.on_delete'];
        yield 'foreign key deferred versus default' => [['CREATE TABLE p (id INTEGER PRIMARY KEY)', 'CREATE TABLE t (id INTEGER PRIMARY KEY, r INTEGER REFERENCES p (id) DEFERRABLE INITIALLY DEFERRED)'], ['CREATE TABLE p (id INTEGER PRIMARY KEY)', 'CREATE TABLE t (id INTEGER PRIMARY KEY, r INTEGER REFERENCES p (id))'], 'foreign_keys.0.deferrable'];
        yield 'foreign key deferred versus immediate' => [['CREATE TABLE p (id INTEGER PRIMARY KEY)', 'CREATE TABLE t (id INTEGER PRIMARY KEY, r INTEGER REFERENCES p (id) DEFERRABLE INITIALLY DEFERRED)'], ['CREATE TABLE p (id INTEGER PRIMARY KEY)', 'CREATE TABLE t (id INTEGER PRIMARY KEY, r INTEGER REFERENCES p (id) DEFERRABLE INITIALLY IMMEDIATE)'], 'foreign_keys.0.deferrable'];
        yield 'foreign key immediate versus not deferrable' => [['CREATE TABLE p (id INTEGER PRIMARY KEY)', 'CREATE TABLE t (id INTEGER PRIMARY KEY, r INTEGER REFERENCES p (id) DEFERRABLE INITIALLY IMMEDIATE)'], ['CREATE TABLE p (id INTEGER PRIMARY KEY)', 'CREATE TABLE t (id INTEGER PRIMARY KEY, r INTEGER REFERENCES p (id) NOT DEFERRABLE)'], 'foreign_keys.0.deferrable'];
        yield 'duplicate foreign keys with one deferrability changed' => [
            ['CREATE TABLE p (id INTEGER PRIMARY KEY)', 'CREATE TABLE t (id INTEGER PRIMARY KEY, r INTEGER, FOREIGN KEY (r) REFERENCES p (id) DEFERRABLE INITIALLY IMMEDIATE, FOREIGN KEY (r) REFERENCES p (id) DEFERRABLE INITIALLY IMMEDIATE)'],
            ['CREATE TABLE p (id INTEGER PRIMARY KEY)', 'CREATE TABLE t (id INTEGER PRIMARY KEY, r INTEGER, FOREIGN KEY (r) REFERENCES p (id) DEFERRABLE INITIALLY IMMEDIATE, FOREIGN KEY (r) REFERENCES p (id) DEFERRABLE INITIALLY DEFERRED)'],
            'foreign_keys.0.deferrable',
        ];
        yield 'double-quoted default is a literal whose case matters' => [['CREATE TABLE t (id INTEGER PRIMARY KEY, s TEXT DEFAULT "A")'], ['CREATE TABLE t (id INTEGER PRIMARY KEY, s TEXT DEFAULT "a")'], 'columns.1.default'];
        yield 'double-quoted token inside a trigger body keeps its case' => [
            [$base, 'CREATE TRIGGER guard BEFORE INSERT ON t BEGIN SELECT RAISE(ABORT, \'no\') WHERE NEW.s = "A"; END'],
            [$base, 'CREATE TRIGGER guard BEFORE INSERT ON t BEGIN SELECT RAISE(ABORT, \'no\') WHERE NEW.s = "a"; END'],
            'triggers.0',
        ];
        yield 'double-quoted token inside a CHECK is ambiguous and fails closed' => [['CREATE TABLE t (id INTEGER PRIMARY KEY, n INTEGER CHECK ("n" > 0))'], ['CREATE TABLE t (id INTEGER PRIMARY KEY, n INTEGER CHECK (n > 0))'], 'checks.0'];
        yield 'double-quoted token inside an index expression is ambiguous and fails closed' => [[$base, 'CREATE INDEX s_idx ON t (lower("s"))'], [$base, 'CREATE INDEX s_idx ON t (lower(s))'], 'indexes.0.expressions.0'];
        // With no column A, SQLite indexes the string literal "A": an
        // expression index whose only token is double-quoted.
        yield 'standalone double-quoted string expression index keeps its case' => [[$base, 'CREATE INDEX expr_idx ON t ("A")'], [$base, 'CREATE INDEX expr_idx ON t ("a")'], 'indexes.0.expressions.0'];
        yield 'foreign key removed' => [['CREATE TABLE p (id INTEGER PRIMARY KEY)', 'CREATE TABLE t (id INTEGER PRIMARY KEY, r INTEGER REFERENCES p (id))'], ['CREATE TABLE p (id INTEGER PRIMARY KEY)', 'CREATE TABLE t (id INTEGER PRIMARY KEY, r INTEGER)'], 'foreign_keys.0'];
        yield 'trigger added' => [[$base], [$base, 'CREATE TRIGGER guard BEFORE INSERT ON t BEGIN SELECT RAISE(ABORT, \'no\') WHERE NEW.n < 0; END'], 'triggers.0'];
        yield 'trigger body changed' => [[$base, 'CREATE TRIGGER guard BEFORE INSERT ON t BEGIN SELECT RAISE(ABORT, \'no\') WHERE NEW.n < 0; END'], [$base, 'CREATE TRIGGER guard BEFORE INSERT ON t BEGIN SELECT RAISE(ABORT, \'no\') WHERE NEW.n < 1; END'], 'triggers.0'];
        yield 'string literal case is significant' => [['CREATE TABLE t (id INTEGER PRIMARY KEY, s TEXT DEFAULT \'A\')'], ['CREATE TABLE t (id INTEGER PRIMARY KEY, s TEXT DEFAULT \'a\')'], 'columns.1.default'];
    }

    #[Test]
    #[DataProvider('semanticChanges')]
    public function semantic_changes_are_named_by_their_first_differing_part(array $left, array $right, string $expectedPath): void
    {
        $difference = SqliteSchemaSignature::firstDifference(self::describe($left), self::describe($right));

        self::assertSame($expectedPath, $difference);
    }

    #[Test]
    public function a_double_quoted_default_is_kept_verbatim_because_sqlite_may_read_it_as_a_literal(): void
    {
        $upper = self::describe(['CREATE TABLE t (id INTEGER PRIMARY KEY, s TEXT DEFAULT "A")']);
        $lower = self::describe(['CREATE TABLE t (id INTEGER PRIMARY KEY, s TEXT DEFAULT "a")']);
        $single = self::describe(["CREATE TABLE t (id INTEGER PRIMARY KEY, s TEXT DEFAULT 'A')"]);

        self::assertSame('"A"', $upper['columns'][1]['default']);
        self::assertSame('"a"', $lower['columns'][1]['default']);
        self::assertSame('columns.1.default', SqliteSchemaSignature::firstDifference($upper, $lower));
        // A double-quoted and a single-quoted literal are different tokens
        // too; the ambiguity is never resolved by guessing.
        self::assertSame('columns.1.default', SqliteSchemaSignature::firstDifference($upper, $single));
    }

    #[Test]
    public function a_double_quoted_token_in_a_trigger_body_is_kept_verbatim_while_the_header_is_an_identifier(): void
    {
        $base = 'CREATE TABLE t (id INTEGER PRIMARY KEY, s TEXT)';
        $upper = self::describe([$base, 'CREATE TRIGGER guard BEFORE INSERT ON "t" BEGIN SELECT RAISE(ABORT, \'no\') WHERE NEW.s = "A"; END']);
        $lower = self::describe([$base, 'CREATE TRIGGER guard BEFORE INSERT ON t BEGIN SELECT RAISE(ABORT, \'no\') WHERE NEW.s = "a"; END']);
        $sameLower = self::describe([$base, 'CREATE TRIGGER "guard" BEFORE INSERT ON t BEGIN SELECT RAISE(ABORT, \'no\') WHERE NEW.s = "a"; END']);

        self::assertStringContainsString('ON T BEGIN', $upper['triggers'][0]);
        self::assertStringContainsString('NEW . S = "A"', $upper['triggers'][0]);
        self::assertStringContainsString('NEW . S = "a"', $lower['triggers'][0]);
        self::assertSame('triggers.0', SqliteSchemaSignature::firstDifference($upper, $lower));
        self::assertNull(SqliteSchemaSignature::firstDifference($lower, $sameLower));
    }

    #[Test]
    public function deferrability_stays_attached_to_the_constraint_with_its_own_actions(): void
    {
        // Same parent, columns, action multiset, and deferrability multiset on
        // both sides; only which constraint is deferred differs.
        $parent = 'CREATE TABLE p (id INTEGER PRIMARY KEY)';
        $cascadeDeferred = self::describe([$parent, 'CREATE TABLE t (id INTEGER PRIMARY KEY, r INTEGER, FOREIGN KEY (r) REFERENCES p (id) ON DELETE CASCADE DEFERRABLE INITIALLY DEFERRED, FOREIGN KEY (r) REFERENCES p (id) ON DELETE RESTRICT DEFERRABLE INITIALLY IMMEDIATE)']);
        $restrictDeferred = self::describe([$parent, 'CREATE TABLE t (id INTEGER PRIMARY KEY, r INTEGER, FOREIGN KEY (r) REFERENCES p (id) ON DELETE CASCADE DEFERRABLE INITIALLY IMMEDIATE, FOREIGN KEY (r) REFERENCES p (id) ON DELETE RESTRICT DEFERRABLE INITIALLY DEFERRED)']);
        $updateVariant = self::describe([$parent, 'CREATE TABLE t (id INTEGER PRIMARY KEY, r INTEGER, FOREIGN KEY (r) REFERENCES p (id) ON UPDATE SET NULL DEFERRABLE INITIALLY DEFERRED, FOREIGN KEY (r) REFERENCES p (id) ON UPDATE SET DEFAULT)']);
        $updateSwapped = self::describe([$parent, 'CREATE TABLE t (id INTEGER PRIMARY KEY, r INTEGER, FOREIGN KEY (r) REFERENCES p (id) ON UPDATE SET NULL, FOREIGN KEY (r) REFERENCES p (id) ON UPDATE SET DEFAULT DEFERRABLE INITIALLY DEFERRED)']);

        $difference = SqliteSchemaSignature::firstDifference($cascadeDeferred, $restrictDeferred);
        self::assertNotNull($difference);
        self::assertMatchesRegularExpression('/^foreign_keys\.\d+\.deferrable$/', $difference);
        foreach ($cascadeDeferred['foreign_keys'] as $foreignKey) {
            self::assertSame(
                $foreignKey['on_delete'] === 'CASCADE' ? 'DEFERRABLE INITIALLY DEFERRED' : 'DEFERRABLE INITIALLY IMMEDIATE',
                $foreignKey['deferrable'],
            );
        }
        $updateDifference = SqliteSchemaSignature::firstDifference($updateVariant, $updateSwapped);
        self::assertNotNull($updateDifference);
        self::assertMatchesRegularExpression('/^foreign_keys\.\d+\.deferrable$/', $updateDifference);
    }

    #[Test]
    public function description_is_stable_across_reads_and_carries_no_ddl_text(): void
    {
        $sql = ["CREATE TABLE t (\n  id INTEGER PRIMARY KEY,\n  s TEXT NOT NULL DEFAULT 'x' CHECK (length(s) > 0)\n)", 'CREATE INDEX s_idx ON t (s COLLATE NOCASE) WHERE s <> \'\''];
        $first = self::describe($sql);
        $second = self::describe($sql);

        self::assertSame($first, $second);
        self::assertSame('TEXT', $first['columns'][1]['type']);
        self::assertSame("'x'", $first['columns'][1]['default']);
        self::assertSame(['LENGTH ( S ) > 0'], $first['checks']);
        self::assertSame("S <> ''", $first['indexes'][0]['where']);
        self::assertTrue($first['rowid_alias']);
        self::assertStringNotContainsString("\n", json_encode($first, JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<string> $statements
     * @return array<string, mixed>
     */
    private static function describe(array $statements): array
    {
        $pdo = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }

        return SqliteSchemaSignature::describe($pdo, 't');
    }
}
