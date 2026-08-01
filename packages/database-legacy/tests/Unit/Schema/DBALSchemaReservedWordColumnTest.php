<?php

declare(strict_types=1);

namespace Waaseyaa\Database\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\Schema\DBALSchema;

/**
 * #2163: `fieldExists()` must see a column whose name is a reserved SQL word.
 *
 * Doctrine keys `listTableColumns()` by the **quoted** name when the column
 * name needs quoting, so the array key is `'"key"'` while the Column object's
 * `getName()` is `'key'`. `isset($columns[$field])` therefore returned false for
 * every reserved-word column, and the lie propagated:
 *
 *  - `SqlColumnSchemaBuilder::addFieldColumn()`'s "already there?" guard was
 *    defeated, so a second `db:init` aborted with `ColumnAlreadyExists`;
 *  - `SqlBlobBackend::read()`/`write()` use the same call to choose between a
 *    real column and the `_data` JSON blob, so a reserved-word field was read
 *    and written in the wrong place — silently.
 *
 * These tests run against real SQLite, not a mocked schema manager: the whole
 * defect lives in what the driver actually returns.
 */
#[CoversClass(DBALSchema::class)]
final class DBALSchemaReservedWordColumnTest extends TestCase
{
    private DBALDatabase $database;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
    }

    private function schema(): DBALSchema
    {
        $schema = $this->database->schema();
        self::assertInstanceOf(DBALSchema::class, $schema);

        return $schema;
    }

    /**
     * Reserved words that a real application can plausibly want as a field
     * name. Deliberately created through raw quoted DDL, because that is the
     * only way such a column can exist in the first place.
     *
     * @return iterable<string, array{string}>
     */
    public static function reservedWords(): iterable
    {
        yield 'key' => ['key'];
        yield 'order' => ['order'];
        yield 'group' => ['group'];
        yield 'index' => ['index'];
        yield 'values' => ['values'];
    }

    #[Test]
    #[DataProvider('reservedWords')]
    public function field_exists_sees_a_reserved_word_column(string $word): void
    {
        $this->database->getConnection()->executeStatement(
            \sprintf('CREATE TABLE reserved_probe (id INTEGER PRIMARY KEY, "%s" TEXT, ordinary TEXT)', $word),
        );

        self::assertTrue(
            $this->schema()->fieldExists('reserved_probe', $word),
            \sprintf('"%s" exists as a column and fieldExists() must say so', $word),
        );
    }

    #[Test]
    public function ordinary_columns_still_resolve(): void
    {
        // The fast direct-key path must keep working — the fix adds a fallback,
        // it does not replace the lookup.
        $this->database->getConnection()->executeStatement(
            'CREATE TABLE reserved_probe (id INTEGER PRIMARY KEY, "key" TEXT, ordinary TEXT, snake_case_name TEXT)',
        );

        foreach (['id', 'ordinary', 'snake_case_name'] as $column) {
            self::assertTrue($this->schema()->fieldExists('reserved_probe', $column), $column);
        }
    }

    #[Test]
    public function absent_columns_still_return_false(): void
    {
        // The fallback must not turn into "return true for anything".
        $this->database->getConnection()->executeStatement(
            'CREATE TABLE reserved_probe (id INTEGER PRIMARY KEY, "key" TEXT, ordinary TEXT)',
        );

        foreach (['missing', 'ke', 'keys', 'ordinar', '"key"'] as $column) {
            self::assertFalse(
                $this->schema()->fieldExists('reserved_probe', $column),
                \sprintf('"%s" does not exist as a column', $column),
            );
        }
    }

    #[Test]
    public function a_missing_table_still_returns_false(): void
    {
        self::assertFalse($this->schema()->fieldExists('no_such_table', 'key'));
    }

    #[Test]
    public function the_quoted_key_is_what_doctrine_actually_returns(): void
    {
        // Pins the premise the fix rests on. If a future Doctrine release stops
        // quoting the array key, this fails and tells us the fallback is now
        // dead weight rather than leaving us guessing.
        $this->database->getConnection()->executeStatement(
            'CREATE TABLE reserved_probe (id INTEGER PRIMARY KEY, "key" TEXT)',
        );

        $columns = $this->database->getConnection()->createSchemaManager()->listTableColumns('reserved_probe');
        $keys = array_keys($columns);

        self::assertContains('"key"', $keys, 'Doctrine is expected to key reserved-word columns by their quoted name');
        self::assertNotContains('key', $keys, 'and NOT by the bare name — which is why isset() missed it');

        // Whereas the Column object knows its canonical, unquoted name.
        $names = array_map(static fn ($c): string => $c->getName(), array_values($columns));
        self::assertContains('key', $names);
    }
}
