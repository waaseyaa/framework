<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Migration;

/**
 * The seam through which the migration runtime asks the canonical entity-schema
 * materializer to create an absent entity base table.
 *
 * `foundation` is layer 0 and cannot import `entity-storage`, so the runtime
 * never learns *how* a table is built or *which* types exist. It names tables;
 * the implementation, supplied by the composition site, decides which of them it
 * owns and materializes exactly those. Tables it does not own are left absent so
 * the migration fails closed against a real SQL error.
 *
 * Implementations run inside the caller's transaction and must be idempotent.
 *
 * @see docs/change-records/FW-2701.md — C1 targeted materialization
 * @api
 */
interface EntityTableMaterializerInterface
{
    /**
     * Materialize whichever of these tables this materializer owns and finds absent.
     *
     * @param list<string> $tables candidate table names, in plan order
     * @return list<string> the tables actually created, for audit
     */
    public function materialize(array $tables): array;
}
