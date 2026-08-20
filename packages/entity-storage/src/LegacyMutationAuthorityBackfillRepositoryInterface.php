<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage;

/**
 * Explicit legacy-row authority repair implemented by the framework repository
 * for the restricted pre-DB-03 upgrade command.
 *
 * @internal Restricted upgrade orchestration only.
 */
interface LegacyMutationAuthorityBackfillRepositoryInterface
{
    /**
     * Create missing aggregate mutation authorities without replacing existing ones.
     */
    public function backfillMutationAuthorities(string $reason): int;
}
