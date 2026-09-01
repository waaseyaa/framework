<?php

declare(strict_types=1);

namespace Waaseyaa\Database;

/** Portable additive foreign-key operations for migration/self-heal paths. */
interface ForeignKeySchemaInterface extends SchemaInterface
{
    /** @param list<string> $columns @param list<string> $referencedColumns */
    public function addForeignKey(
        string $table,
        string $name,
        array $columns,
        string $referencedTable,
        array $referencedColumns,
        array $options = [],
    ): void;

    /**
     * Read-only introspection: does a foreign key with this name already
     * exist on the table? No DDL — safe to call from a production runtime
     * readiness assertion (#2761).
     */
    public function foreignKeyExists(string $table, string $name): bool;
}
