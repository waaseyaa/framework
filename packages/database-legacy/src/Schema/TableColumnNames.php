<?php

declare(strict_types=1);

namespace Waaseyaa\Database\Schema;

use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;

/**
 * The canonical column names of a table.
 *
 * Doctrine keys `listTableColumns()` by the column's **quoted** name whenever
 * the identifier needs quoting: a column named `key` arrives under the array
 * key `'"key"'` while the `Column` object's `getName()` is the canonical
 * `'key'`. `array_keys()` on that map therefore yields identifier *literals*,
 * not column names.
 *
 * That mistake has now been made three times independently — `DBALSchema::fieldExists()`
 * (#2163), and both the artifact-side and boot-side halves of the field-access
 * preflight (#2171) — so this exists to be the only way anyone asks the
 * question. Prefer it over touching `listTableColumns()` directly.
 *
 * Deliberately does not inspect quote characters or consult a reserved-word
 * list: which identifiers need quoting is the platform's business, and
 * `getName()` already answers it portably for every driver.
 *
 * @api
 */
final readonly class TableColumnNames
{
    /**
     * Canonical, unquoted column names, in Doctrine's order.
     *
     * @return list<string>
     */
    public static function for(AbstractSchemaManager $schema, string $table): array
    {
        return array_values(array_map(
            static fn(Column $column): string => $column->getName(),
            $schema->listTableColumns($table),
        ));
    }

    /**
     * Canonical column names, sorted — the shape schema fingerprints consume.
     *
     * @return list<string>
     */
    public static function sortedFor(AbstractSchemaManager $schema, string $table): array
    {
        $names = self::for($schema, $table);
        sort($names);

        return $names;
    }
}
