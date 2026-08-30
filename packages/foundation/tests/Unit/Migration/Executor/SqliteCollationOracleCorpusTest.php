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
use Waaseyaa\Foundation\Migration\Executor\SqliteTableDefinition;
use Waaseyaa\Foundation\Schema\Compiler\Sqlite\Translator\AddIndexTranslator;
use Waaseyaa\Foundation\Schema\Diff\AddIndex;

/**
 * A wide, **two-sided** differential corpus for the DDL collation reader.
 *
 * {@see SqliteCollationOracleTest} states the one-sided safety property: a
 * non-null answer must agree with SQLite, and unknown is always permitted
 * because it fails closed. That is the property that matters for correctness,
 * and it is deliberately permissive — a reader that returned unknown for
 * *everything* would satisfy it completely while refusing every migration.
 *
 * This file closes that side. Every row names the collation SQLite actually
 * uses, and asserts **both** that the expectation is true of SQLite and that
 * the parser produces exactly it. A row cannot pass by reporting unknown,
 * so the corpus measures how much of real DDL the reader can actually resolve,
 * not merely that it never lies.
 *
 * The oracle is built the same way in both files: create the table from the
 * literal DDL, create the index the **real compiler** would emit through
 * {@see AddIndexTranslator}, and read the collation that index actually uses
 * from `PRAGMA index_xinfo` — for an authored index declaring no collation,
 * that is the column's own.
 *
 * Unknown is proved separately in {@see self::unresolvableDdl()}, on input that
 * is not valid stored DDL, so it is never used to make a supported row pass.
 *
 * @see docs/change-records/FW-2701.md
 */
#[CoversClass(SqliteTableDefinition::class)]
#[CoversClass(OpPreconditionResolver::class)]
final class SqliteCollationOracleCorpusTest extends TestCase
{
    /** Held so the in-memory database outlives the connection handle. */
    private DBALDatabase $database;

    #[Test]
    #[DataProvider('commentSeparatedClauses')]
    #[DataProvider('repeatedClauses')]
    #[DataProvider('identifiersResemblingTheKeyword')]
    #[DataProvider('fabricatedClauses')]
    #[DataProvider('quotedCollationNames')]
    #[DataProvider('leadingComments')]
    #[DataProvider('quotedIdentifiersAndLiterals')]
    #[DataProvider('nestedExpressions')]
    #[DataProvider('retainedRegressions')]
    public function the_parser_reports_what_sqlite_does(string $ddl, string $column, string $expected): void
    {
        // The oracle first: an expectation this test asserts must itself be a
        // fact about SQLite, not a belief about the parser.
        self::assertSame(
            $expected,
            $this->effectiveCollation($ddl, $column),
            'the expectation in this row does not match real SQLite',
        );

        self::assertSame(
            $expected,
            new SqliteTableDefinition($ddl)->collationOf($column),
            'the parser contradicts SQLite (or reports unknown for a supported form)',
        );
    }

    /**
     * A comment is a token separator, never a deletion.
     *
     * Removing the comment characters from a stream welds the tokens either
     * side of it together, which hides a real clause. The clause is then not
     * merely unreadable but invisible, so the read falls through to the
     * authoritative `BINARY` default and wrongly accepts a case-sensitive index
     * for a case-insensitive column.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function commentSeparatedClauses(): array
    {
        return [
            'block comment between COLLATE and its argument' => [
                'CREATE TABLE t (name TEXT COLLATE/* c */NOCASE)', 'name', 'NOCASE',
            ],
            'line comment between COLLATE and its argument' => [
                "CREATE TABLE t (name TEXT COLLATE-- c\nNOCASE)", 'name', 'NOCASE',
            ],
            'multi-line block comment between COLLATE and its argument' => [
                "CREATE TABLE t (name TEXT COLLATE/* multi\nline */NOCASE)", 'name', 'NOCASE',
            ],
            'two adjacent comments between COLLATE and its argument' => [
                'CREATE TABLE t (name TEXT COLLATE/*a*//*b*/NOCASE)', 'name', 'NOCASE',
            ],
            'comment immediately before the keyword' => [
                'CREATE TABLE t (name TEXT/* c */COLLATE NOCASE)', 'name', 'NOCASE',
            ],
            'line comment immediately before the keyword' => [
                "CREATE TABLE t (name TEXT-- c\nCOLLATE NOCASE)", 'name', 'NOCASE',
            ],
            'comment between a constraint and the keyword' => [
                'CREATE TABLE t (name TEXT NOT NULL/* c */COLLATE NOCASE)', 'name', 'NOCASE',
            ],
            'comment between an untyped column name and the keyword' => [
                'CREATE TABLE t (name/* c */COLLATE NOCASE)', 'name', 'NOCASE',
            ],
            'line comment between an untyped column name and the keyword' => [
                "CREATE TABLE t (name-- c\nCOLLATE NOCASE)", 'name', 'NOCASE',
            ],
            'comment between a parenthesised type and the keyword' => [
                'CREATE TABLE t (name VARCHAR(10)/* c */COLLATE NOCASE)', 'name', 'NOCASE',
            ],
            'comment splitting the column name' => [
                'CREATE TABLE t (na/* c */me TEXT COLLATE NOCASE)', 'na', 'NOCASE',
            ],
            'comment splitting a constraint keyword' => [
                'CREATE TABLE t (name TEXT UNI/* c */QUE COLLATE NOCASE)', 'name', 'NOCASE',
            ],
        ];
    }

    /**
     * SQLite applies the **last** `COLLATE` on a column definition.
     *
     * A reader that returns on its first match is wrong in both directions: it
     * under-reports (accepting a BINARY index for a NOCASE column) and
     * over-reports (refusing an index that is in fact equivalent).
     *
     * @return array<string, array{string, string, string}>
     */
    public static function repeatedClauses(): array
    {
        return [
            'the later clause wins' => [
                'CREATE TABLE t (name TEXT COLLATE BINARY COLLATE NOCASE)', 'name', 'NOCASE',
            ],
            'the later clause wins in the other direction' => [
                'CREATE TABLE t (name TEXT COLLATE NOCASE COLLATE BINARY)', 'name', 'BINARY',
            ],
            'the later clause wins between two non-default collations' => [
                'CREATE TABLE t (name TEXT COLLATE NOCASE COLLATE RTRIM)', 'name', 'RTRIM',
            ],
            'the last of three clauses wins' => [
                'CREATE TABLE t (name TEXT COLLATE BINARY COLLATE RTRIM COLLATE NOCASE)', 'name', 'NOCASE',
            ],
            'repeated clauses on an untyped column' => [
                'CREATE TABLE t (name COLLATE BINARY COLLATE NOCASE)', 'name', 'NOCASE',
            ],
            'repeated clauses separated by NOT NULL' => [
                'CREATE TABLE t (name TEXT COLLATE BINARY NOT NULL COLLATE NOCASE)', 'name', 'NOCASE',
            ],
            'repeated clauses separated by a string DEFAULT' => [
                "CREATE TABLE t (name TEXT COLLATE BINARY DEFAULT 'a' COLLATE NOCASE)", 'name', 'NOCASE',
            ],
            'repeated clauses separated by a CHECK constraint' => [
                'CREATE TABLE t (name TEXT COLLATE BINARY CHECK (length(name) < 10) COLLATE NOCASE)',
                'name', 'NOCASE',
            ],
            'repeated clauses separated by a column-level UNIQUE' => [
                'CREATE TABLE t (name TEXT COLLATE BINARY UNIQUE COLLATE NOCASE)', 'name', 'NOCASE',
            ],
            'repeated clauses separated by PRIMARY KEY' => [
                'CREATE TABLE t (name TEXT COLLATE BINARY PRIMARY KEY COLLATE NOCASE)', 'name', 'NOCASE',
            ],
            'repeated clauses separated by a conflict clause' => [
                'CREATE TABLE t (name TEXT COLLATE BINARY NOT NULL ON CONFLICT ROLLBACK COLLATE NOCASE)',
                'name', 'NOCASE',
            ],
            'repeated clauses separated by a line comment' => [
                "CREATE TABLE t (name TEXT COLLATE BINARY -- c\n COLLATE NOCASE)", 'name', 'NOCASE',
            ],
            'repeated clauses separated by a block comment' => [
                'CREATE TABLE t (name TEXT COLLATE BINARY /* c */ COLLATE NOCASE)', 'name', 'NOCASE',
            ],
            'repeated clauses ending in the default' => [
                'CREATE TABLE t (name TEXT COLLATE RTRIM COLLATE BINARY)', 'name', 'BINARY',
            ],
            'repeated clauses on a quoted column' => [
                'CREATE TABLE t ("name" TEXT COLLATE BINARY COLLATE NOCASE)', 'name', 'NOCASE',
            ],
            'repeated clauses in lower case' => [
                'CREATE TABLE t (name TEXT collate nocase collate binary)', 'name', 'BINARY',
            ],
            'a later quoted clause still wins' => [
                'CREATE TABLE t (name TEXT COLLATE BINARY COLLATE "NOCASE")', 'name', 'NOCASE',
            ],
            'repeated identical clauses stay resolved' => [
                'CREATE TABLE t (name TEXT COLLATE NOCASE COLLATE NOCASE)', 'name', 'NOCASE',
            ],
            'repeated clauses across newlines' => [
                "CREATE TABLE t (\n  name TEXT\n    COLLATE BINARY\n    COLLATE NOCASE\n)", 'name', 'NOCASE',
            ],
        ];
    }

    /**
     * The keyword is matched as a whole token, so it cannot hit inside a name.
     *
     * A pattern with no leading word boundary matches the tail of `acollate`
     * and then reads whatever follows as the collation, inventing an
     * authoritative answer — sometimes a collation that does not exist.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function identifiersResemblingTheKeyword(): array
    {
        return [
            'a column name ending in the keyword' => [
                'CREATE TABLE t (acollate TEXT COLLATE NOCASE)', 'acollate', 'NOCASE',
            ],
            'a column name ending in the keyword with a constraint between' => [
                'CREATE TABLE t (acollate TEXT NOT NULL COLLATE NOCASE)', 'acollate', 'NOCASE',
            ],
            'a keyword-suffixed name with repeated clauses' => [
                'CREATE TABLE t (acollate TEXT COLLATE BINARY COLLATE NOCASE)', 'acollate', 'NOCASE',
            ],
            'a keyword-suffixed name whose type is BINARY' => [
                'CREATE TABLE t (acollate BINARY COLLATE NOCASE)', 'acollate', 'NOCASE',
            ],
            'a keyword-suffixed name with no clause at all' => [
                'CREATE TABLE t (xcollate TEXT)', 'xcollate', 'BINARY',
            ],
            'an underscore before the keyword' => [
                'CREATE TABLE t (a_collate TEXT COLLATE NOCASE)', 'a_collate', 'NOCASE',
            ],
            'a dollar sign before the keyword' => [
                'CREATE TABLE t (a$collate TEXT COLLATE NOCASE)', 'a$collate', 'NOCASE',
            ],
            'mixed case does not protect the identifier' => [
                'CREATE TABLE t (XCollate TEXT COLLATE NOCASE)', 'XCollate', 'NOCASE',
            ],
            'a digit-bearing keyword-suffixed name' => [
                'CREATE TABLE t (c2collate TEXT COLLATE NOCASE)', 'c2collate', 'NOCASE',
            ],
            'a type name ending in the keyword' => [
                'CREATE TABLE t (a xcollate COLLATE NOCASE)', 'a', 'NOCASE',
            ],
            'a referenced table name ending in the keyword' => [
                'CREATE TABLE t (name TEXT REFERENCES acollate(id) COLLATE NOCASE)', 'name', 'NOCASE',
            ],
            'a referenced table name ending in the keyword with no column list' => [
                'CREATE TABLE t (name TEXT REFERENCES acollate COLLATE NOCASE)', 'name', 'NOCASE',
            ],
            'a referenced table name ending in the keyword and no clause' => [
                'CREATE TABLE t (name TEXT REFERENCES acollate)', 'name', 'BINARY',
            ],
            'the trailing boundary still holds' => [
                'CREATE TABLE t (collatex TEXT COLLATE NOCASE)', 'collatex', 'NOCASE',
            ],
            'a non-ASCII bare identifier' => [
                'CREATE TABLE t (naïve TEXT COLLATE NOCASE)', 'naïve', 'NOCASE',
            ],
        ];
    }

    /**
     * Deleting characters can *invent* a clause as easily as hide one.
     *
     * `COLL/* c *\/ATE` is three SQLite tokens folded into a multi-word type
     * name, so the column is BINARY. A reader that strips the comment from a
     * character stream fabricates `COLLATE NOCASE` and reports NOCASE for a
     * genuinely case-sensitive column. This is the same defect as the hidden
     * clause, in the opposite direction.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function fabricatedClauses(): array
    {
        return [
            'a comment splitting the keyword does not create one' => [
                'CREATE TABLE t (name TEXT COLL/* c */ATE NOCASE)', 'name', 'BINARY',
            ],
            'a comment splitting the keyword earlier' => [
                'CREATE TABLE t (name TEXT COL/* c */LATE NOCASE)', 'name', 'BINARY',
            ],
            'a line comment splitting the keyword' => [
                "CREATE TABLE t (name TEXT COLL-- c\nATE NOCASE)", 'name', 'BINARY',
            ],
            'a double-quoted word splitting the keyword' => [
                'CREATE TABLE t (a COL"z"LATE NOCASE)', 'a', 'BINARY',
            ],
            'a bracketed word splitting the keyword' => [
                'CREATE TABLE t (a COL[z]LATE NOCASE)', 'a', 'BINARY',
            ],
            'a backticked word splitting the keyword' => [
                'CREATE TABLE t (a COL`z`LATE NOCASE)', 'a', 'BINARY',
            ],
            'the same type name written with spaces' => [
                'CREATE TABLE t (a COL "z" LATE NOCASE)', 'a', 'BINARY',
            ],
            'a fabricated clause must not shadow the real one' => [
                'CREATE TABLE t (a COL"z"LATE BINARY COLLATE NOCASE)', 'a', 'NOCASE',
            ],
            'a quoted word inside the column name' => [
                'CREATE TABLE t(col"x"late BINARY COLLATE NOCASE)', 'col', 'NOCASE',
            ],
        ];
    }

    /**
     * SQLite accepts every quoting form where a collation name is expected.
     *
     * Deleting quoted regions before the scan leaves the clause argument
     * unreadable, so a legitimately NOCASE column is reported unknown — and,
     * worse, the scan can run on and read the *next* keyword as the name.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function quotedCollationNames(): array
    {
        return [
            'double-quoted collation name' => [
                'CREATE TABLE t (name TEXT COLLATE "NOCASE")', 'name', 'NOCASE',
            ],
            'bracket-quoted collation name' => [
                'CREATE TABLE t (name TEXT COLLATE [NOCASE])', 'name', 'NOCASE',
            ],
            'backtick-quoted collation name' => [
                'CREATE TABLE t (name TEXT COLLATE `NOCASE`)', 'name', 'NOCASE',
            ],
            'string-literal collation name' => [
                "CREATE TABLE t (name TEXT COLLATE 'NOCASE')", 'name', 'NOCASE',
            ],
            'quoted collation name with no separating space' => [
                'CREATE TABLE t (name TEXT COLLATE"NOCASE")', 'name', 'NOCASE',
            ],
            'quoted BINARY is still authoritative BINARY' => [
                'CREATE TABLE t (name TEXT COLLATE "BINARY")', 'name', 'BINARY',
            ],
            'a quoted name does not swallow the following constraint' => [
                'CREATE TABLE t (name TEXT COLLATE "NOCASE" NOT NULL)', 'name', 'NOCASE',
            ],
            'a quoted name does not swallow the following DEFAULT' => [
                "CREATE TABLE t (name TEXT COLLATE \"NOCASE\" DEFAULT 'x')", 'name', 'NOCASE',
            ],
            'a quoted name does not swallow a following clause' => [
                'CREATE TABLE t (name TEXT COLLATE "NOCASE" COLLATE BINARY)', 'name', 'BINARY',
            ],
            'a lower-case string-literal name is normalised' => [
                "CREATE TABLE t (name TEXT COLLATE 'nocase')", 'name', 'NOCASE',
            ],
        ];
    }

    /**
     * A comment before a column definition must not lose the column.
     *
     * Trimming whitespace off a raw definition does not get past a comment, so
     * the definition is skipped and the whole table reports unknown — the shape
     * hand-authored DDL takes most often.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function leadingComments(): array
    {
        return [
            'block comment before the first column' => [
                'CREATE TABLE t (/* c */name TEXT COLLATE NOCASE)', 'name', 'NOCASE',
            ],
            'line comment before the first column' => [
                "CREATE TABLE t (-- c\n name TEXT COLLATE NOCASE)", 'name', 'NOCASE',
            ],
            'block comment before a later column' => [
                'CREATE TABLE t (id INTEGER, /* c */name TEXT COLLATE NOCASE)', 'name', 'NOCASE',
            ],
            'a leading comment must not lose the BINARY default either' => [
                'CREATE TABLE t (id INTEGER, /* the name */ name TEXT)', 'name', 'BINARY',
            ],
            'block comment before a quoted column name' => [
                'CREATE TABLE t (/* c */"name" TEXT COLLATE NOCASE)', 'name', 'NOCASE',
            ],
            'a trailing line comment on the previous column' => [
                "CREATE TABLE t(\n  id INTEGER, -- the id\n  name TEXT COLLATE NOCASE\n)", 'name', 'NOCASE',
            ],
        ];
    }

    /**
     * Quoted identifiers and string literals are opaque, single tokens.
     *
     * Their interiors are never scanned for keywords and their boundaries are
     * never crossed by a comma split or a paren-depth count.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function quotedIdentifiersAndLiterals(): array
    {
        return [
            'a column literally named collate' => [
                'CREATE TABLE t ("collate" TEXT COLLATE NOCASE)', 'collate', 'NOCASE',
            ],
            'a comma inside a quoted identifier does not split' => [
                'CREATE TABLE t ("a,b" TEXT COLLATE NOCASE)', 'a,b', 'NOCASE',
            ],
            'an open paren inside a bracketed identifier' => [
                'CREATE TABLE t ([a(b] TEXT COLLATE NOCASE)', 'a(b', 'NOCASE',
            ],
            'a close paren inside a quoted identifier' => [
                'CREATE TABLE t ("a)b" TEXT COLLATE NOCASE)', 'a)b', 'NOCASE',
            ],
            'a line-comment opener inside a quoted identifier' => [
                'CREATE TABLE t ("a--b" TEXT COLLATE NOCASE)', 'a--b', 'NOCASE',
            ],
            'a block-comment opener inside a quoted identifier' => [
                'CREATE TABLE t ("a/*b" TEXT COLLATE NOCASE)', 'a/*b', 'NOCASE',
            ],
            'the keyword spelled inside a bracketed column name' => [
                'CREATE TABLE t ([x COLLATE NOCASE] TEXT)', 'x COLLATE NOCASE', 'BINARY',
            ],
            'the keyword spelled inside a quoted column name' => [
                'CREATE TABLE t ("COLLATE NOCASE" TEXT)', 'COLLATE NOCASE', 'BINARY',
            ],
            'a doubled quote inside a string default' => [
                "CREATE TABLE t (name TEXT DEFAULT '''' COLLATE NOCASE)", 'name', 'NOCASE',
            ],
            'a blob literal default' => [
                "CREATE TABLE t (name TEXT DEFAULT x'41' COLLATE NOCASE)", 'name', 'NOCASE',
            ],
            'an unbalanced open paren inside a string default' => [
                "CREATE TABLE t (name TEXT DEFAULT '(' COLLATE NOCASE)", 'name', 'NOCASE',
            ],
            'an unbalanced close paren inside a string default' => [
                "CREATE TABLE t (name TEXT DEFAULT ')' COLLATE NOCASE)", 'name', 'NOCASE',
            ],
            'a quoted type name' => [
                'CREATE TABLE t (name "TEXT" COLLATE NOCASE)', 'name', 'NOCASE',
            ],
            'a non-ASCII quoted identifier' => [
                'CREATE TABLE t ("naïve" TEXT COLLATE NOCASE)', 'naïve', 'NOCASE',
            ],
        ];
    }

    /**
     * A clause inside a parenthesised payload is not the column's collation.
     *
     * Depth is counted from bracket *tokens*, so a paren inside a literal or a
     * comment cannot move it.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function nestedExpressions(): array
    {
        return [
            'a clause in a parenthesised DEFAULT is not the column collation' => [
                "CREATE TABLE t (name TEXT DEFAULT ('x' COLLATE NOCASE))", 'name', 'BINARY',
            ],
            'a decoy in a parenthesised DEFAULT plus the real clause' => [
                "CREATE TABLE t (name TEXT DEFAULT ('x' COLLATE NOCASE) COLLATE NOCASE)", 'name', 'NOCASE',
            ],
            'a CHECK abutting the keyword' => [
                "CREATE TABLE t (name TEXT CHECK(name<>'')COLLATE NOCASE)", 'name', 'NOCASE',
            ],
            'unbalanced parens inside a comment in a CHECK' => [
                "CREATE TABLE t (name TEXT CHECK (name <> '' /* ) ( */ ) COLLATE NOCASE)", 'name', 'NOCASE',
            ],
            'an unbalanced paren inside a CHECK string literal' => [
                "CREATE TABLE t (name TEXT CHECK (name <> '(') COLLATE NOCASE)", 'name', 'NOCASE',
            ],
            'commas inside a CHECK IN list' => [
                "CREATE TABLE t (name TEXT CHECK (name IN ('a','b')) COLLATE NOCASE)", 'name', 'NOCASE',
            ],
            'a table-level CHECK does not leak its clause' => [
                "CREATE TABLE t (name TEXT, CHECK (name COLLATE NOCASE <> ''))", 'name', 'BINARY',
            ],
            'a named table-level CHECK does not leak its clause' => [
                "CREATE TABLE t (name TEXT, CONSTRAINT ck CHECK (name COLLATE NOCASE <> ''))", 'name', 'BINARY',
            ],
            'a table-level UNIQUE clause is not the column collation' => [
                'CREATE TABLE t (name TEXT, UNIQUE (name COLLATE NOCASE))', 'name', 'BINARY',
            ],
            'a table-level PRIMARY KEY clause is not the column collation' => [
                'CREATE TABLE t (name TEXT, PRIMARY KEY (name COLLATE NOCASE))', 'name', 'BINARY',
            ],
            'a generated-column expression is not the column collation' => [
                'CREATE TABLE t (src TEXT, name TEXT GENERATED ALWAYS AS (src COLLATE NOCASE) VIRTUAL)',
                'name', 'BINARY',
            ],
            'two table constraints with decoy clauses' => [
                'CREATE TABLE t (other TEXT, name TEXT COLLATE NOCASE, UNIQUE(name COLLATE BINARY), '
                . "CHECK(name COLLATE RTRIM <> ''))",
                'name', 'NOCASE',
            ],
            'a quoted keyword column beside a table constraint' => [
                'CREATE TABLE t ("check" TEXT COLLATE NOCASE, UNIQUE ("check"))', 'check', 'NOCASE',
            ],
            'a bracketed keyword column beside a table constraint' => [
                "CREATE TABLE t ([check] TEXT COLLATE NOCASE, CHECK([check] <> ''))", 'check', 'NOCASE',
            ],
            'table options after the definition list' => [
                'CREATE TABLE t (name TEXT COLLATE NOCASE, PRIMARY KEY(name COLLATE BINARY)) STRICT, WITHOUT ROWID',
                'name', 'NOCASE',
            ],
            'a multi-word type name' => [
                'CREATE TABLE t (name UNSIGNED BIG INT COLLATE NOCASE)', 'name', 'NOCASE',
            ],
            'a two-word type name' => [
                'CREATE TABLE t (name DOUBLE PRECISION COLLATE NOCASE)', 'name', 'NOCASE',
            ],
            'a multi-word type name with a size' => [
                'CREATE TABLE t (name NATIVE CHARACTER(70) COLLATE NOCASE)', 'name', 'NOCASE',
            ],
            'a keyword DEFAULT before the clause' => [
                'CREATE TABLE t (name TEXT DEFAULT CURRENT_TIMESTAMP COLLATE NOCASE)', 'name', 'NOCASE',
            ],
            'a foreign-key tail after the clause' => [
                'CREATE TABLE t (name TEXT COLLATE NOCASE REFERENCES t2 '
                . 'ON DELETE SET NULL DEFERRABLE INITIALLY DEFERRED)',
                'name', 'NOCASE',
            ],
            'a generated column declaring its own collation' => [
                'CREATE TABLE t (src TEXT, name TEXT COLLATE NOCASE GENERATED ALWAYS AS (upper(src)) VIRTUAL)',
                'name', 'NOCASE',
            ],
            'a table-level FOREIGN KEY constraint is skipped' => [
                'CREATE TABLE t (a TEXT, name TEXT COLLATE NOCASE, '
                . 'FOREIGN KEY (a) REFERENCES t2(b) ON UPDATE CASCADE)',
                'name', 'NOCASE',
            ],
        ];
    }

    /**
     * Every row the character scanner already answered correctly.
     *
     * These are carried forward verbatim so the rewrite is proved to preserve
     * behaviour, not merely to change it.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function retainedRegressions(): array
    {
        return [
            'no collate declared is authoritative BINARY' => [
                'CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)', 'name', 'BINARY',
            ],
            'explicit collate' => [
                'CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT COLLATE NOCASE)', 'name', 'NOCASE',
            ],
            'collate is upper-cased' => [
                'CREATE TABLE t (name TEXT collate nocase)', 'name', 'NOCASE',
            ],
            'double-quoted identifier' => [
                'CREATE TABLE t ("name" TEXT COLLATE RTRIM)', 'name', 'RTRIM',
            ],
            'bracket-quoted identifier' => [
                'CREATE TABLE t ([name] TEXT COLLATE NOCASE)', 'name', 'NOCASE',
            ],
            'backtick-quoted identifier' => [
                'CREATE TABLE t (`name` TEXT COLLATE NOCASE)', 'name', 'NOCASE',
            ],
            'escaped quote inside identifier' => [
                'CREATE TABLE t ("od""d" TEXT COLLATE NOCASE)', 'od"d', 'NOCASE',
            ],
            'column lookup is case-insensitive' => [
                'CREATE TABLE t (Name TEXT COLLATE NOCASE)', 'nAmE', 'NOCASE',
            ],
            'a COLLATE inside a string default belongs to the string' => [
                "CREATE TABLE t (name TEXT DEFAULT 'COLLATE NOCASE')", 'name', 'BINARY',
            ],
            'a COLLATE inside a CHECK expression is not the column collation' => [
                'CREATE TABLE t (name TEXT CHECK (name = upper(name) COLLATE NOCASE))', 'name', 'BINARY',
            ],
            'a later column collation does not leak to an earlier one' => [
                'CREATE TABLE t (a TEXT, b TEXT COLLATE NOCASE)', 'a', 'BINARY',
            ],
            'a prefix-named column is not confused with a longer one' => [
                'CREATE TABLE t (name TEXT, nameish TEXT COLLATE NOCASE)', 'name', 'BINARY',
            ],
            'the longer column is still found' => [
                'CREATE TABLE t (name TEXT, nameish TEXT COLLATE NOCASE)', 'nameish', 'NOCASE',
            ],
            'table constraints carrying commas are skipped' => [
                'CREATE TABLE t (a TEXT, b TEXT, UNIQUE (a, b), CHECK (a <> b))', 'b', 'BINARY',
            ],
            'a named table constraint is skipped' => [
                'CREATE TABLE t (a TEXT COLLATE NOCASE, CONSTRAINT c UNIQUE (a))', 'a', 'NOCASE',
            ],
            'a line comment cannot introduce a collation' => [
                "CREATE TABLE t (\n name TEXT, -- COLLATE NOCASE\n other TEXT\n)", 'name', 'BINARY',
            ],
            'a block comment cannot introduce a collation' => [
                'CREATE TABLE t (name TEXT /* COLLATE NOCASE */, other TEXT)', 'name', 'BINARY',
            ],
            'a parenthesised type does not break the split' => [
                'CREATE TABLE t (amount DECIMAL(10, 2), name TEXT COLLATE NOCASE)', 'name', 'NOCASE',
            ],
        ];
    }

    #[Test]
    #[DataProvider('unresolvableDdl')]
    public function it_reports_unknown_rather_than_guessing(string $ddl, string $column): void
    {
        // Unknown must never collapse to BINARY: callers fail closed on null,
        // and a guess would silently accept a mismatched index.
        self::assertNull(new SqliteTableDefinition($ddl)->collationOf($column));
    }

    /**
     * Input the model does not cover.
     *
     * None of these is valid, complete `CREATE TABLE` DDL that SQLite would
     * store, so there is no oracle to differ from — which is exactly why they
     * must be refused rather than answered.
     *
     * @return array<string, array{string, string}>
     */
    public static function unresolvableDdl(): array
    {
        return [
            'column absent' => ['CREATE TABLE t (a TEXT)', 'missing'],
            'no column list at all' => ['CREATE TABLE t', 'a'],
            'unbalanced parentheses' => ['CREATE TABLE t (a TEXT', 'a'],
            'empty ddl' => ['', 'a'],
            'collate with no argument' => ['CREATE TABLE t (a TEXT COLLATE)', 'a'],
            'a view definition is not a column list we model' => [
                'CREATE VIEW t AS SELECT 1 AS a', 'a',
            ],
            'a create-table-as-select has no column definitions' => [
                'CREATE TABLE t AS SELECT 1 AS a', 'a',
            ],
            'a trailing clause with no argument poisons the read' => [
                'CREATE TABLE t (a TEXT COLLATE NOCASE COLLATE)', 'a',
            ],
            'a numeric collation argument is not a name' => [
                'CREATE TABLE t (a TEXT COLLATE 1)', 'a',
            ],
            'ddl ending inside a string literal' => [
                "CREATE TABLE t (a TEXT DEFAULT 'x)", 'a',
            ],
            'ddl ending inside a quoted identifier' => [
                'CREATE TABLE t ("a TEXT)', 'a',
            ],
            'ddl ending inside a block comment' => [
                'CREATE TABLE t (a TEXT /* )', 'a',
            ],
        ];
    }

    /**
     * The three defect shapes, driven through the real precondition resolver.
     *
     * The harm is concrete: the resolver compares the collation an authored
     * index would inherit against the collation the live index actually uses.
     * A wrong `BINARY` makes it accept a case-sensitive unique index for a
     * case-insensitive column, so `'a'` and `'A'` both survive an index the
     * author declared to reject one of them.
     */
    #[Test]
    #[DataProvider('resolverDefectShapes')]
    public function the_resolver_refuses_an_index_that_only_looked_equivalent(string $tableDdl): void
    {
        $connection = $this->connection();
        $connection->executeStatement($tableDdl);
        // A BINARY unique index — what a legacy or foreign database holds — is
        // not the NOCASE index the authored operation would create.
        $connection->executeStatement('CREATE UNIQUE INDEX idx_names ON account (name COLLATE BINARY)');

        $this->expectException(IncompatibleSchemaStateException::class);
        $this->expectExceptionMessageMatches('/would index under NOCASE \(inherited from the column\), found BINARY/');

        new OpPreconditionResolver($connection)->resolve(new AddIndex('account', ['name'], 'idx_names', true));
    }

    #[Test]
    #[DataProvider('resolverDefectShapes')]
    public function the_resolver_accepts_the_index_the_compiler_would_emit(string $tableDdl): void
    {
        $connection = $this->connection();
        $connection->executeStatement($tableDdl);
        // The index the real compiler emits declares no collation, so it
        // inherits the column's — which is what equivalence means here.
        $connection->executeStatement(
            AddIndexTranslator::translate(new AddIndex('account', ['name'], 'idx_names', true))->sql(),
        );

        self::assertSame(
            OpPrecondition::AlreadySatisfied,
            new OpPreconditionResolver($connection)->resolve(new AddIndex('account', ['name'], 'idx_names', true)),
        );
    }

    /** @return array<string, array{string}> */
    public static function resolverDefectShapes(): array
    {
        return [
            'a comment hides the clause' => [
                'CREATE TABLE account (eid INTEGER PRIMARY KEY, name TEXT COLLATE/* c */NOCASE)',
            ],
            'a later clause overrides an earlier one' => [
                'CREATE TABLE account (eid INTEGER PRIMARY KEY, name TEXT COLLATE BINARY COLLATE NOCASE)',
            ],
            'a neighbouring identifier ends in the keyword' => [
                'CREATE TABLE account (acollate BINARY, name TEXT COLLATE NOCASE)',
            ],
            'a quoted collation name' => [
                'CREATE TABLE account (eid INTEGER PRIMARY KEY, name TEXT COLLATE "NOCASE")',
            ],
            'a comment precedes the column definition' => [
                'CREATE TABLE account (eid INTEGER PRIMARY KEY, /* the name */ name TEXT COLLATE NOCASE)',
            ],
        ];
    }

    /**
     * The collation SQLite really uses for an index the compiler would author.
     *
     * `PRAGMA index_xinfo` reports the collation of each indexed column; for an
     * index that declares none, that is the column's declared collation. This
     * is the oracle — no expectation in this file is trusted without it.
     */
    private function effectiveCollation(string $ddl, string $column): string
    {
        $connection = $this->connection();
        $connection->executeStatement($ddl);
        $connection->executeStatement(
            AddIndexTranslator::translate(new AddIndex('t', [$column], 'ix_oracle'))->sql(),
        );

        foreach ($connection->fetchAllAssociative("PRAGMA index_xinfo('ix_oracle')") as $entry) {
            if ((int) ($entry['key'] ?? 0) === 1) {
                return strtoupper(trim((string) ($entry['coll'] ?? '')));
            }
        }

        self::fail(sprintf('PRAGMA index_xinfo reported no key column for "%s"', $column));
    }

    private function connection(): Connection
    {
        $this->database = DBALDatabase::createSqlite();

        return $this->database->getConnection();
    }
}
