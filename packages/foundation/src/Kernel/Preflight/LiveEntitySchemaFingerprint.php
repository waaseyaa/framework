<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel\Preflight;

use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\Schema\TableColumnNames;
use Waaseyaa\Entity\Preflight\EntityStorageSchemaShape;

/**
 * Boot-side live schema fingerprint for the field-access activation guard.
 *
 * Computes the fingerprint over registered entity-storage tables only (base
 * table + `<type>__*` subtables) via the shared {@see EntityStorageSchemaShape}
 * canonicalization, in lockstep with the artifact-side scanner
 * (`Waaseyaa\CLI\Security\DatabaseFieldAccessInventoryScanner`). Lazily-created
 * non-entity runtime tables (rate limiting, publishing idempotency, …) do not
 * participate, so a first-use table materialized after deploy cannot stale the
 * preflight (#2143).
 *
 * @internal
 */
final readonly class LiveEntitySchemaFingerprint
{
    /** @param list<string> $entityTypeIds */
    public static function compute(DBALDatabase $database, array $entityTypeIds): string
    {
        $schema = $database->getConnection()->createSchemaManager();
        $shape = [];
        foreach ($schema->listTableNames() as $table) {
            if (!EntityStorageSchemaShape::belongsToEntityStorage($table, $entityTypeIds)) {
                continue;
            }
            // Canonical names, never `array_keys()` (#2171) — see the matching
            // comment in DatabaseFieldAccessInventoryScanner. Both halves were
            // consistently quoted before the fix, so they agreed by accident;
            // correcting either alone makes every production boot fail as
            // "stale for the current schema" on a reserved-word column.
            $shape[$table] = TableColumnNames::sortedFor($schema, $table);
        }

        return EntityStorageSchemaShape::fingerprint($shape);
    }
}
