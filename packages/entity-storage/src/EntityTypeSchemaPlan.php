<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage;

/**
 * Outcome of {@see EntitySchemaSync::planMutatingEntityTypeIds()}.
 *
 * Splits the entity types under consideration into those the planner could
 * genuinely determine need a schema mutation ({@see $mutating}) and those it
 * could not determine either way ({@see $indeterminate}) because the
 * underlying read-only preview mechanism
 * ({@see CoordinatedEntitySchemaExecutor::canPreviewMutation()}) is unavailable
 * — off SQLite, or while a mutation is already active on the connection. An
 * entity type never appears in both lists; one whose determination succeeded
 * and found no mutation needed appears in neither (#2732).
 *
 * @api
 */
final class EntityTypeSchemaPlan
{
    /**
     * @param list<string> $mutating Entity-type ids confirmed to need a schema mutation.
     * @param list<string> $indeterminate Entity-type ids whose mutation status could not be
     *   determined on this database platform/coordinator state — neither a confirmed
     *   mutation nor a confirmed no-op.
     */
    public function __construct(
        public readonly array $mutating,
        public readonly array $indeterminate,
    ) {}
}
