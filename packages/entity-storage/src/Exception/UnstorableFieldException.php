<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Exception;

/**
 * A value cannot be persisted, and would otherwise have been silently dropped
 * (#2165).
 *
 * This exists because the failure it replaces was invisible. On a table with no
 * `_data` blob column — every `sql-column` entity type — the driver's write
 * split routed some values into a JSON bucket that was then only flushed when a
 * blob column existed. With no blob column the bucket was discarded, `save()`
 * returned success, nothing was logged, and the value was gone. An application
 * could persist member-entered content for weeks and find out from a blank
 * page.
 *
 * The rule is now explicit: on a table with no `_data` column, **every value
 * must have a column and must be storable in it**. Anything else raises this,
 * rather than being dropped.
 *
 * @api
 */
final class UnstorableFieldException extends \RuntimeException
{
    /**
     * A value has no column to go in and no blob to fall back on.
     *
     * @param list<string> $fields Field names, sorted, that could not be stored.
     */
    public static function noColumnAvailable(string $entityTypeId, array $fields): self
    {
        sort($fields);

        return new self(\sprintf(
            'Entity type "%s" cannot store the value(s) [%s]: the table has no column for them and no _data blob '
            . 'to fall back on, so writing would silently discard them. On the sql-column backend every declared '
            . 'field is materialised as a column, so this usually means the value was set on the entity without '
            . 'being declared as a #[Field], or the schema is out of date — run db:init to synchronise it.',
            $entityTypeId,
            implode(', ', $fields),
        ));
    }

    /**
     * A value has a column, but this driver has no encoding for its type.
     */
    public static function unencodableValue(string $entityTypeId, string $field, string $type): self
    {
        return new self(\sprintf(
            'Entity type "%s" cannot store field "%s": the value is of type %s and the SQL driver has no column '
            . 'encoding for it, so it could be written but never reloaded faithfully. Store a scalar (or null), '
            . 'or route the field to a backend that supports structured values.',
            $entityTypeId,
            $field,
            $type,
        ));
    }
}
