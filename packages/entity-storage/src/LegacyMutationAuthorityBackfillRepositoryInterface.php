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
    /** Whether this repository has the framework database authority boundary. */
    public function supportsMutationAuthorityBackfill(): bool;

    /**
     * Create missing aggregate mutation authorities without replacing existing ones.
     *
     * @throws Exception\MutationAuthorityBackfillException with the exact committed count on failure
     */
    public function backfillMutationAuthorities(string $reason): int;
}
