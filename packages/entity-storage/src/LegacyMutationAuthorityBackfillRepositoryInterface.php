<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage;

/**
 * Explicit legacy-row authority repair implemented by the framework repository.
 *
 * @internal Restricted upgrade orchestration only.
 */
/**
 * Restricted repository seam for the explicit pre-DB-03 upgrade command.
 *
 * @internal
 */
interface LegacyMutationAuthorityBackfillRepositoryInterface
{
    /**
     * Create missing aggregate mutation authorities without replacing existing ones.
     */
    public function backfillMutationAuthorities(string $reason): int;
}
